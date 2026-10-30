# Changelog

Versions are published to Packagist from a read-only mirror by hand; a version without a date is not published yet.

## 0.3.0

Requires `honkio/honkio-php` ^0.3.

### Added

- Honkio Auth sign-in (not available yet). A `honkio` Socialite driver (install `laravel/socialite` ^5.25): OpenID Connect against your app's own issuer (`HONKIO_AUTH_ISSUER`, read from its discovery document), authorization code with PKCE, a session nonce, `ui_locales` from the app locale, and the ID and access tokens checked before `user()` returns a `Honkio\Laravel\Socialite\HonkioUser` (claims, ID token, token set). `logoutUrl()` builds the sign-out URL.
- `Honkio\Laravel\Auth\HonkioSession` keeps the tokens in the session and applies the outage rule once per request, under a cache lock: users stay signed in while Honkio can't be reached, for at most 24 hours past the access token's expiry, and are signed out as soon as Honkio refuses a refresh. A 429 or 408 lets that request through on the old tokens without marking it offline. A request that waited on the lock takes the new tokens from its own session's last refresh (30 seconds, deleted at sign-out), never from the refresh token alone.
- The `honkio.auth` middleware and a `honkio` auth guard (registered as `auth.guards.honkio` unless you define it) that apply that rule. While Honkio is unreachable, `$request->attributes->get('honkio_auth_offline')` is true.
- `config/honkio.php` gains `auth` (`HONKIO_AUTH_ISSUER`, `HONKIO_AUTH_CLIENT_ID`, `HONKIO_AUTH_CLIENT_SECRET`, `HONKIO_AUTH_REDIRECT_URI`, `HONKIO_AUTH_CACHE_STORE`, `HONKIO_AUTH_LOGOUT_GUARD`); `services.honkio` overrides it. Signing keys are cached, encrypted, in your cache store.
- `Honkio::authApps()` on the facade (through `honkio/honkio-php` 0.3.0).

## 0.2.0 (2026-10-17)

Requires `honkio/honkio-php` ^0.2.

### Added

- The client's automatic retries (from `honkio/honkio-php` 0.2.0) are set by `max_retries` (default 2; 0 turns them off) and `max_retry_delay` (seconds, default 60) in `config/honkio.php`, from `HONKIO_MAX_RETRIES` and `HONKIO_MAX_RETRY_DELAY`.
- Through `honkio/honkio-php` 0.2.0: `Honkio::sendLimit()->get()` (the daily cap and usage, and each number's carrier queue in `number_queues`), `estimated_send_at` on a sent message, and a `Retry-After` given as an HTTP date.

## 0.1.0 (2026-10-14)

First release. Supports Laravel 12 and 13 on PHP 8.2 or newer (Laravel 11 is end of life and is not supported).

### Added

- `honkio` mail transport (Symfony Mailer) that sends through `POST /v1/emails`. Mail is transactional by default; the header `X-HonkIO-Commercial: true` marks a message commercial, and the API's refusals are thrown as the SDK's exceptions.
- `honkio` SMS notification channel with `HonkioMessage`, sending an idempotency key derived from the notification id and the recipient so a retried queued job never sends twice.
- `Honkio` facade and a shared `Honkio\Honkio` client built from `config/honkio.php` (`HONKIO_API_KEY`, `HONKIO_BASE_URL`, `HONKIO_SMS_FROM`), publishable under the tag `honkio-config`.
