<?php

declare(strict_types=1);

namespace Honkio\Laravel\Socialite;

use GuzzleHttp\RequestOptions;
use Honkio\Auth\Cache;
use Honkio\Auth\HonkioAuth;
use Honkio\Auth\TokenSet;
use Honkio\Exception\TokenVerificationException;
use Illuminate\Support\Arr;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;

/**
 * The `honkio` Socialite driver: OpenID Connect sign-in with Honkio Auth.
 *
 * Every app has its own issuer (`https://` plus the app's hostname); the
 * endpoints come from its discovery document. The flow is authorization code
 * with PKCE (S256), a `state` and a `nonce` kept in the session, and
 * `ui_locales` from the app locale (en or fr). user() checks the ID token
 * (signature, issuer, audience, nonce) and the access token before returning.
 *
 * ```php
 * return Socialite::driver('honkio')->redirect();
 * $user = Socialite::driver('honkio')->user();      // HonkioUser
 * app(HonkioSession::class)->login($user);           // keep the tokens server-side
 * ```
 *
 * For a second app, build another provider with its own issuer:
 * `Socialite::buildProvider(HonkioProvider::class, $config)->forIssuer('https://login.other.ca')`.
 */
class HonkioProvider extends AbstractProvider
{
    public const NONCE_SESSION_KEY = 'honkio_auth_nonce';

    /** @var list<string> */
    protected $scopes = ['openid', 'email'];

    protected $scopeSeparator = ' ';

    protected $usesPKCE = true;

    private ?string $issuer = null;

    private ?HonkioAuth $honkioAuth = null;

    private ?Cache $authCache = null;

    /** Sets the app's issuer; the provider's HTTP client and the given cache are used for discovery and keys. */
    public function forIssuer(string $issuer, ?Cache $cache = null): static
    {
        $this->issuer = $issuer;
        $this->authCache = $cache;
        $this->honkioAuth = null;

        return $this;
    }

    /** Uses an already built HonkioAuth (its issuer and client id must be this app's). */
    public function withHonkioAuth(HonkioAuth $auth): static
    {
        $this->honkioAuth = $auth;
        $this->issuer = $auth->issuer();

        return $this;
    }

    public function honkioAuth(): HonkioAuth
    {
        if ($this->honkioAuth === null) {
            if ($this->issuer === null || $this->issuer === '') {
                throw new \InvalidArgumentException('The honkio Socialite driver has no issuer: set HONKIO_AUTH_ISSUER (the app\'s https://hostname).');
            }
            $options = [
                'issuer' => $this->issuer,
                'client_id' => (string) $this->clientId,
                'client_secret' => (string) $this->clientSecret !== '' ? (string) $this->clientSecret : null,
                'http_client' => $this->getHttpClient(),
            ];
            if ($this->authCache !== null) {
                $options['cache'] = $this->authCache;
            }
            $this->honkioAuth = new HonkioAuth($options);
        }

        return $this->honkioAuth;
    }

    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase($this->endpoint('authorization_endpoint'), $state);
    }

    protected function getTokenUrl(): string
    {
        return $this->endpoint('token_endpoint');
    }

    /**
     * @param string|null $state
     * @return array<string, mixed>
     */
    protected function getCodeFields($state = null): array
    {
        $fields = parent::getCodeFields($state);
        if (!isset($fields['nonce'])) {
            $fields['nonce'] = bin2hex(random_bytes(16));
        }
        if (!$this->isStateless()) {
            $this->request->session()->put(self::NONCE_SESSION_KEY, $fields['nonce']);
        }
        if (!isset($fields['ui_locales'])) {
            $locale = strtolower(substr((string) app()->getLocale(), 0, 2));
            if (in_array($locale, ['en', 'fr'], true)) {
                $fields['ui_locales'] = $locale;
            }
        }

        return $fields;
    }

    /**
     * A public client (no secret) sends no client_secret at all.
     *
     * @param string $code
     * @return array<string, mixed>
     */
    protected function getTokenFields($code): array
    {
        $fields = parent::getTokenFields($code);
        if (!is_string($fields['client_secret'] ?? null) || $fields['client_secret'] === '') {
            unset($fields['client_secret']);
        }
        // Authorize-only parameters (nonce, ui_locales) don't belong in the token request.
        unset($fields['nonce'], $fields['ui_locales'], $fields['prompt'], $fields['max_age'], $fields['login_hint'], $fields['organization'], $fields['acr_values']);

        return $fields;
    }

    /**
     * @throws InvalidStateException when the state or nonce is missing or does not match
     * @throws TokenVerificationException when a token fails its checks
     */
    public function user(): HonkioUser
    {
        if ($this->user instanceof HonkioUser) {
            return $this->user;
        }
        if ($this->hasInvalidState()) {
            throw new InvalidStateException();
        }
        $nonce = null;
        if (!$this->isStateless()) {
            $nonce = $this->request->session()->pull(self::NONCE_SESSION_KEY);
            if (!is_string($nonce) || $nonce === '') {
                throw new InvalidStateException('The sign-in nonce is missing from the session.');
            }
        }

        $response = $this->getAccessTokenResponse($this->getCode());
        $tokens = $this->honkioAuth()->tokenSetFromResponse(is_array($response) ? $response : [], $nonce);

        return $this->userFromTokenSet($tokens, Arr::get(is_array($response) ? $response : [], 'scope'));
    }

    /**
     * The user for a token set already checked (by user(), or by your own code
     * through HonkioAuth::tokenSetFromResponse()).
     */
    public function userFromTokenSet(TokenSet $tokens, mixed $scope = null): HonkioUser
    {
        $claims = $this->honkioAuth()->verifyIdToken((string) $tokens->idToken);
        $info = $this->getUserByToken($tokens->accessToken);
        if (isset($info['sub']) && $info['sub'] !== $claims['sub']) {
            throw new TokenVerificationException('missing_subject', 'userinfo names another user than the ID token.');
        }
        // Profile fields from userinfo may be newer; the identity claims are the ID token's.
        $merged = array_merge($claims, $info, array_intersect_key($claims, array_flip(['sub', 'iss', 'aud', 'sid', 'amr', 'acr', 'auth_time', 'org_id', 'org_slug', 'org_role'])));

        /** @var HonkioUser $user */
        $user = $this->mapUserToObject($merged);
        $user->setToken($tokens->accessToken)
            ->setRefreshToken($tokens->refreshToken)
            ->setExpiresIn(max(0, $tokens->expiresAt - time()))
            ->setApprovedScopes(explode(' ', is_string($scope) ? $scope : $tokens->scope));
        $user->setHonkioTokens($tokens, $merged);
        $this->user = $user;

        return $user;
    }

    /**
     * The userinfo claims, or [] when the app publishes no userinfo endpoint.
     *
     * @param string $token
     * @return array<string, mixed>
     */
    protected function getUserByToken($token): array
    {
        $endpoint = $this->honkioAuth()->configuration()['userinfo_endpoint'] ?? null;
        if (!is_string($endpoint) || $endpoint === '') {
            return [];
        }
        $response = $this->getHttpClient()->get($endpoint, [
            RequestOptions::HEADERS => ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $token],
        ]);
        $body = json_decode((string) $response->getBody(), true);

        return is_array($body) ? $body : [];
    }

    /** @param array<string, mixed> $user */
    protected function mapUserToObject(array $user): HonkioUser
    {
        return (new HonkioUser())->setRaw($user)->map([
            'id' => $user['sub'] ?? null,
            'nickname' => null,
            'name' => $user['name'] ?? null,
            'email' => $user['email'] ?? null,
            'avatar' => null,
        ]);
    }

    /**
     * Where to send the browser to sign out of the hosted page too (RP-initiated logout).
     */
    public function logoutUrl(?string $idToken = null, ?string $postLogoutRedirectUri = null, ?string $state = null): string
    {
        return $this->honkioAuth()->logoutUrl($idToken, $postLogoutRedirectUri, $state);
    }

    private function endpoint(string $name): string
    {
        $url = $this->honkioAuth()->configuration()[$name] ?? null;

        return is_string($url) ? $url : '';
    }
}
