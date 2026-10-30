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
 * The signed-in user's Honkio Auth tokens, kept in the Laravel session (never
 * in a cookie the browser can read), and the outage-tolerant refresh rule
 * applied to them once per request:
 *
 * - while Honkio can't be reached the user stays signed in, for at most 24
 *   hours past the access token's expiry;
 * - a refresh Honkio refuses (revoked, blocked, deleted, removed from the
 *   organization) signs them out at once.
 *
 * Refreshes are serialised per session with a cache lock (when the cache
 * store supports locks), because refresh tokens rotate and reusing one ends
 * the whole session.
 */
class HonkioSession
{
    public const SESSION_KEY = 'honkio_auth';

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
            $this->forget();
        }

        return $this->result = $result;
    }

    /** True when this request is running on kept tokens because Honkio can't be reached. */
    public function offline(): bool
    {
        return $this->result !== null && $this->result->status === RefreshResult::KEPT;
    }

    /** Forgets the tokens (sign-out on your side). Call logoutUrl() first to end the hosted session too. */
    public function forget(): void
    {
        $this->session->forget(self::SESSION_KEY);
        $this->result = null;
    }

    /**
     * Where to send the browser to sign out of the hosted page as well, with
     * the stored ID token as the hint. Null when Honkio can't be reached.
     */
    public function logoutUrl(?string $postLogoutRedirectUri = null, ?string $state = null): ?string
    {
        try {
            return $this->auth->logoutUrl($this->tokens()?->idToken, $postLogoutRedirectUri, $state);
        } catch (HonkioException) {
            return null;
        }
    }

    private function refreshLocked(TokenSet $tokens): RefreshResult
    {
        $store = $this->cache?->store();
        $lockStore = $store?->getStore();
        if (!$lockStore instanceof LockProvider) {
            return $this->auth->refresh($tokens);
        }
        $lock = $lockStore->lock('honkio-auth:lock:' . hash('sha256', (string) ($tokens->refreshToken ?? $tokens->accessToken)), 30);
        try {
            /** @var RefreshResult */
            return $lock->block(15, fn (): RefreshResult => $this->auth->refresh($tokens));
        } catch (LockTimeoutException) {
            // Another request has held the refresh for 15 seconds: Honkio is slow, which is not a refusal.
            return $tokens->expiresAt + TokenRefresher::OUTAGE_GRACE_SECONDS >= time()
                ? RefreshResult::kept($tokens)
                : RefreshResult::ended('outage_limit');
        }
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
