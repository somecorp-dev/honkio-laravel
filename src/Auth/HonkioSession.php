<?php

declare(strict_types=1);

namespace Honkio\Laravel\Auth;

use Honkio\Auth\HonkioAuth;
use Honkio\Auth\RefreshResult;
use Honkio\Auth\TokenRefresher;
use Honkio\Auth\TokenSet;
use Honkio\Exception\HonkioException;
use Honkio\Exception\InvalidArgumentException;
use Honkio\Laravel\Socialite\HonkioUser as SocialiteUser;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Session\Session;

/**
 * The signed-in user's HonkIO Auth tokens, kept in the Laravel session (never
 * in a cookie the browser can read), and the outage-tolerant refresh rule
 * applied to them once per request:
 *
 * - while HonkIO can't be reached the user stays signed in, for at most 24
 *   hours past the access token's expiry;
 * - a refresh HonkIO refuses (revoked, blocked, deleted, removed from the
 *   organization) signs them out at once.
 *
 * Refreshes are serialised per session with a cache lock (when the cache
 * store supports locks), because refresh tokens rotate and reusing one ends
 * the whole session. Laravel saves the session when a request ends, so a
 * request that waited on the lock can't see the new tokens in its own copy
 * of the session yet: after the lock it reads them from this session's entry
 * in the cache, which holds the last refresh for {@see REFRESH_SECONDS},
 * answers only this session id and the refresh token it replaced, and is
 * deleted on forget(), which also revokes the refresh token at HonkIO.
 */
class HonkioSession
{
    public const SESSION_KEY = 'honkio_auth';

    /** How long this session's last refresh stays readable by its other requests still holding the old tokens. */
    public const REFRESH_SECONDS = 30;

    private ?RefreshResult $result = null;

    public function __construct(
        private readonly HonkioAuth $auth,
        private readonly Session $session,
        private readonly ?LaravelCache $cache = null,
    ) {
    }

    /**
     * Keeps a freshly signed-in user's tokens and claims, and gives the
     * session a new id (as Auth::login does) so a fixed session id can't be
     * carried across sign-in.
     */
    public function login(SocialiteUser $user): void
    {
        $this->store($user->tokenSet(), $user->claims);
        $this->session->migrate(true);
    }

    /**
     * Keeps a token set obtained some other way (your own OIDC client): call
     * HonkioAuth::tokenSetFromResponse() first so the tokens are checked.
     *
     * @param array<string, mixed> $claims the verified ID token claims
     */
    public function store(TokenSet $tokens, array $claims): void
    {
        $this->session->put(self::SESSION_KEY, ['tokens' => $tokens->toArray(), 'claims' => $claims]);
        $this->result = null;
    }

    public function tokens(): ?TokenSet
    {
        $stored = $this->session->get(self::SESSION_KEY);
        if (!is_array($stored) || !is_array($stored['tokens'] ?? null)) {
            return null;
        }
        try {
            return TokenSet::fromArray($stored['tokens']);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** @return array<string, mixed>|null the ID token claims from sign-in, or the latest refresh */
    public function claims(): ?array
    {
        $stored = $this->session->get(self::SESSION_KEY);
        $claims = is_array($stored) ? ($stored['claims'] ?? null) : null;

        return is_array($claims) ? $claims : null;
    }

    /**
     * Applies the refresh rule (at most once per request) and returns what it
     * decided, or null when no one is signed in. An ended session is
     * forgotten here.
     */
    public function ensureFresh(): ?RefreshResult
    {
        if ($this->result !== null) {
            return $this->result;
        }
        $tokens = $this->tokens();
        if ($tokens === null) {
            return null;
        }
        if (!$tokens->isExpired(time(), TokenRefresher::EXPIRY_SKEW_SECONDS)) {
            return $this->result = RefreshResult::valid($tokens);
        }

        $result = $this->refreshLocked($tokens);
        if ($result->changed() && $result->tokens !== null) {
            $this->store($result->tokens, $this->claimsFor($result->tokens));
        } elseif (!$result->isActive()) {
            // Honkio refused the token (nothing left to revoke) or can't be reached: forget it locally.
            $this->clear();
        }

        return $this->result = $result;
    }

    /** True when this request is running on kept tokens because HonkIO can't be reached. */
    public function offline(): bool
    {
        return $this->result !== null && $this->result->isOffline();
    }

    /**
     * Signs the user out: forgets the tokens and this session's last refresh,
     * then revokes the refresh token at HonkIO ({@see HonkioAuth::revoke()}),
     * which also deletes its refresh memo, so neither the token nor a request
     * still holding the one it replaced can bring the session back. When
     * HonkIO can't be reached or refuses, the tokens are forgotten anyway.
     * Call logoutUrl() first to end the hosted session too.
     */
    public function forget(): void
    {
        $refreshToken = $this->tokens()?->refreshToken;
        $this->clear();
        if ($refreshToken === null || $refreshToken === '') {
            return;
        }
        try {
            $this->auth->revoke($refreshToken);
        } catch (HonkioException) {
            // Signed out here regardless; the refresh token expires on its own.
        }
    }

    /**
     * Where to send the browser to sign out of the hosted page as well, with
     * the stored ID token as the hint. Null when HonkIO can't be reached.
     */
    public function logoutUrl(?string $postLogoutRedirectUri = null, ?string $state = null): ?string
    {
        try {
            return $this->auth->logoutUrl($this->tokens()?->idToken, $postLogoutRedirectUri, $state);
        } catch (HonkioException) {
            return null;
        }
    }

    /** Forgets the tokens and this session's last refresh, without calling HonkIO. */
    private function clear(): void
    {
        $this->cache?->store()->forget($this->refreshKey());
        $this->session->forget(self::SESSION_KEY);
        $this->result = null;
    }

    private function refreshLocked(TokenSet $tokens): RefreshResult
    {
        $store = $this->cache?->store();
        $lockStore = $store?->getStore();
        if (!$lockStore instanceof LockProvider) {
            return $this->refreshOnce($tokens);
        }
        $lock = $lockStore->lock('honkio-auth:lock:' . hash('sha256', (string) ($tokens->refreshToken ?? $tokens->accessToken)), 30);
        try {
            /** @var RefreshResult */
            return $lock->block(15, fn (): RefreshResult => $this->refreshOnce($tokens));
        } catch (LockTimeoutException) {
            // Another request has held the refresh for 15 seconds: Honkio is slow, which is not a refusal.
            return $tokens->expiresAt + TokenRefresher::OUTAGE_GRACE_SECONDS >= time()
                ? RefreshResult::kept($tokens)
                : RefreshResult::ended('outage_limit');
        }
    }

    /**
     * Refreshes $tokens, unless another request in this session already has:
     * then its new tokens are used, and the spent refresh token is not sent again.
     */
    private function refreshOnce(TokenSet $tokens): RefreshResult
    {
        $from = hash('sha256', (string) $tokens->refreshToken);
        $latest = $this->cache?->get($this->refreshKey());
        if (is_array($latest) && is_string($latest['from'] ?? null) && hash_equals($latest['from'], $from) && is_array($latest['tokens'] ?? null)) {
            try {
                return RefreshResult::refreshed(TokenSet::fromArray($latest['tokens']));
            } catch (InvalidArgumentException) {
                // not ours; refresh as usual
            }
        }

        $result = $this->auth->refresh($tokens);
        if ($result->changed() && $result->tokens !== null) {
            $this->cache?->set($this->refreshKey(), ['from' => $from, 'tokens' => $result->tokens->toArray()], self::REFRESH_SECONDS);
        }

        return $result;
    }

    private function refreshKey(): string
    {
        return 'honkio-auth:session-refresh:' . hash('sha256', $this->session->getId());
    }

    /** @return array<string, mixed> */
    private function claimsFor(TokenSet $tokens): array
    {
        if ($tokens->idToken !== null) {
            try {
                return $this->auth->verifyIdToken($tokens->idToken);
            } catch (HonkioException) {
                // Checked by the refresher already; keep the previous claims.
            }
        }

        return $this->claims() ?? [];
    }
}
