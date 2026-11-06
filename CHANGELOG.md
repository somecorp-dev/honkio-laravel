# Changelog

Versions are published to Packagist from a read-only mirror by hand; a version without a date is not published yet.

## Unreleased

### Added

- `HonkioMessage::template($idOrAlias, $variables, $language)` and `HonkioPushMessage::template(...)` send a stored template's SMS or push part in place of the text, in English or French (`'en'` or `'fr'`, null for the template's default language).
- **Breaking:** `Honkio::templates()` on the facade now manages all templates (`/v1/templates`), the PHP SDK's resource for templates with email, SMS and push parts in English and French (an SMS-only or French template can now be made from code). The email-only resource (`/v1/email-templates`) is now `Honkio::emailTemplates()` (deprecated).

### Changed

- A template notification retried by the queue (the same notification, so the same Idempotency-Key) replays the SMS it sent even after the template was changed, republished or deleted. The API binds the key to the template, its variables and its language exactly as sent, so a retry that changes any of them (the template's id for its alias, `1` for `"1"`, or a language left out for the default one named) is refused with `IDEMPOTENCY_KEY_REUSED` instead of sending a second text.

## 0.3.1 (2026-11-02)

Requires `honkio/honkio-php` ^0.3.1.

### Security

- `var_dump` and `print_r` no longer show a secret from what the package builds. The `Honkio` client and the mail transport show `[redacted]` for the API key (through `honkio/honkio-php` 0.3.1). The `honkio` Socialite driver shows `[redacted]` for the client secret, which Socialite keeps in a plain property, and leaves out the request. `HonkioSession` leaves out the session store, which holds the user's tokens. `LaravelCache` leaves out the encrypter, which holds the app key.

## 0.3.0 (2026-10-31)

Requires `honkio/honkio-php` ^0.3.

### Added

- HonkIO Auth sign-in. A `honkio` Socialite driver (install `laravel/socialite` ^5.25): OpenID Connect against your app's own issuer (`HONKIO_AUTH_ISSUER`, read from its discovery document), authorization code with PKCE, a session nonce, `ui_locales` from the app locale, and the ID and access tokens checked before `user()` returns a `Honkio\Laravel\Socialite\HonkioUser` (claims, ID token, token set). `logoutUrl()` builds the sign-out URL.
- `Honkio\Laravel\Auth\HonkioSession` keeps the tokens in the session and applies the outage rule once per request, under a cache lock: users stay signed in while Honkio can't be reached, for at most 24 hours past the access token's expiry, and are signed out as soon as Honkio refuses a refresh. A 429 or 408 lets that request through on the old tokens without marking it offline. A request that waited on the lock takes the new tokens from its own session's last refresh (30 seconds, deleted at sign-out), never from the refresh token alone.
- The `honkio.auth` middleware and a `honkio` auth guard (registered as `auth.guards.honkio` unless you define it) that apply that rule. While Honkio is unreachable, `$request->attributes->get('honkio_auth_offline')` is true.
- `config/honkio.php` gains `auth` (`HONKIO_AUTH_ISSUER`, `HONKIO_AUTH_CLIENT_ID`, `HONKIO_AUTH_CLIENT_SECRET`, `HONKIO_AUTH_REDIRECT_URI`, `HONKIO_AUTH_CACHE_STORE`, `HONKIO_AUTH_LOGOUT_GUARD`); `services.honkio` overrides it. Signing keys are cached, encrypted, in your cache store.
- `Honkio::authApps()` on the facade (through `honkio/honkio-php` 0.3.0). Its `users` resource lists and removes a user's passkeys (`passkeys`, `removePasskey`); `update` with a `hostname` moves an app to a new hostname and `cancelHostnameChange` drops the move; `exports->latest` reads the newest export.
- `HonkioSession::forget()` (and the `honkio` guard's `logout()`) revokes the refresh token at Honkio with `HonkioAuth::revoke()`, which also deletes its refresh memo. The tokens are forgotten even when Honkio can't be reached. A refresh Honkio refused is forgotten without calling it again.
- `LaravelCache` implements `Honkio\Auth\DeletableCache`, so `HonkioAuth::revoke()` deletes the refresh memo, and it holds the outage pause per app for every worker.

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
