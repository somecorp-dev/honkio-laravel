# honkio/laravel

[HonkIO](https://honkio.ca) for Laravel: a mail transport, an SMS notification channel and Honkio Auth sign-in on top of [`honkio/honkio-php`](https://honkio.ca/docs/sdk). Canadian SMS and email, with CASL built in. Data is stored in Canada (ca-central-1).

- PHP 8.2 or newer, Laravel 12 or 13 (auto-discovered, no manual provider registration)
- The client retries what waiting fixes (a number's per-minute sending rate, the carrier asking to slow down), at most 2 times by default, with an idempotency key so its retries never send twice; see [Retries](#retries). Notifications carry an idempotency key derived from the notification, so a retried queued job cannot send or charge twice either. Queued mail is not deduplicated across job retries: each send gets a new generated key, so a job retried after a timeout can send twice.

## Install

```sh
composer require honkio/laravel
```

Create a key in the dashboard under API Keys, Create key (<https://honkio.ca/dashboard/keys>). Start with a test key (`mk_test_...`): nothing is delivered or charged.

```dotenv
HONKIO_API_KEY=mk_test_...
HONKIO_SMS_FROM=+14165550100
# MAIL_MAILER=honkio
```

`HONKIO_SMS_FROM` is the default sending number for SMS. `HONKIO_BASE_URL` is only for pointing at another API host. `HONKIO_MAX_RETRIES` (default 2; 0 turns retrying off) and `HONKIO_MAX_RETRY_DELAY` (seconds, default 60) set the client's [retries](#retries). To edit the config, publish it:

```sh
php artisan vendor:publish --tag=honkio-config
```

A missing key fails the first time HonkIO is used, not when the application boots.

## Email

Add a mailer to `config/mail.php`:

```php
'mailers' => [
    'honkio' => ['transport' => 'honkio'],
],
```

Then `MAIL_MAILER=honkio`, or `Mail::mailer('honkio')->to(...)->send(...)`. The `from` address must belong to a verified sending domain on your account. From, to, cc, bcc, reply-to, subject, text, HTML, custom headers and attachments are all sent (if a custom header name is repeated, the last value is sent). The header `X-HonkIO-Commercial` accepts only `true` or `false`; any other value throws before anything is sent.

Email is **transactional by default**. A marketing message must say so, and then the API holds it to CASL: consent on file, and one recipient per message. Mark a Mailable commercial with a header, which the transport removes and sends as `is_commercial: true`:

```php
use Illuminate\Mail\Mailable;
use Symfony\Component\Mime\Email;

class SpringSale extends Mailable
{
    public function build(): static
    {
        return $this
            ->subject('Spring sale')
            ->view('mail.spring-sale')
            ->withSymfonyMessage(fn (Email $message) => $message->getHeaders()->addTextHeader('X-HonkIO-Commercial', 'true'));
    }
}
```

If the API refuses a message (for example `EMAIL_COMMERCIAL_MULTI_RECIPIENT`, or no consent), the SDK exception is thrown from `Mail::send()`, so Laravel's normal failure handling applies. Server errors (5xx) and connection failures arrive as a Symfony `TransportException` with the SDK exception as `getPrevious()`, which is what lets Laravel and the queue treat them as retryable; 4xx refusals arrive as the SDK exception itself. Queued mail is retried by the queue as usual.

The API reserves some headers and refuses a message that sets one with `EMAIL_HEADER_INVALID`: `List-Unsubscribe`, `List-Unsubscribe-Post`, `Content-Disposition`, `ARC-*` and `X-SES-*`.

## SMS notifications

```php
use Honkio\Laravel\Notifications\HonkioMessage;
use Illuminate\Notifications\Notification;

class ShipmentSent extends Notification
{
    public function via($notifiable): array
    {
        return ['honkio'];
    }

    public function toHonkio($notifiable): HonkioMessage
    {
        return HonkioMessage::create('Your order has shipped.')
            ->from('+14165550100'); // optional, defaults to HONKIO_SMS_FROM
    }
}
```

The recipient number (E.164) comes from the notifiable:

```php
class User extends Authenticatable
{
    use Notifiable;

    public function routeNotificationForHonkio($notification): ?string
    {
        return $this->phone_number;
    }
}
```

A notifiable with no number is skipped without calling the API. Every recipient needs consent on file under CASL.

## The client and the facade

```php
use Honkio\Laravel\Facades\Honkio;

Honkio::messages()->send(['from' => '+14165550100', 'to' => '+16135550123', 'body' => 'Hi']);
Honkio::consents()->check(['phone_number' => '+16135550123']);
Honkio::sendLimit()->get(); // the daily cap and usage, and each number's carrier queue (number_queues)
```

`Honkio\Honkio` is bound as a singleton, so you can also type-hint it. See the [PHP SDK](https://honkio.ca/docs/sdk) for every resource.

## Signing users in with Honkio Auth (not available yet)

Honkio Auth is hosted, passwordless sign-in (email code or link, text code, passkeys) on a hostname you own, with users stored in Canada. It is not available yet. Create an app in the dashboard, publish its DNS records, and add your callback URL to its redirect URIs. Then:

```sh
composer require laravel/socialite
```

```dotenv
HONKIO_AUTH_ISSUER=https://login.example.ca
HONKIO_AUTH_CLIENT_ID=...
HONKIO_AUTH_CLIENT_SECRET=...   # leave empty for a public client
HONKIO_AUTH_REDIRECT_URI=/auth/honkio/callback
```

```php
use Honkio\Laravel\Auth\HonkioSession;
use Laravel\Socialite\Facades\Socialite;

Route::get('/login', fn () => Socialite::driver('honkio')->redirect())->name('login');

Route::get('/auth/honkio/callback', function (HonkioSession $session) {
    $user = Socialite::driver('honkio')->user(); // tokens checked: issuer, audience, signature, nonce
    $session->login($user);                       // keeps the tokens in the session, server-side
    // With your own users table, also: Auth::login(User::firstOrCreate(['honkio_id' => $user->getId()], [...]));
    return redirect()->intended('/');
});

Route::middleware('honkio.auth')->group(function () {
    Route::get('/account', fn () => view('account'));
});

Route::post('/logout', function (HonkioSession $session) {
    $url = $session->logoutUrl(url('/')); // signs out of the hosted page too
    $session->forget();
    return $url ? redirect()->away($url) : redirect('/');
});
```

Without a users table of your own, use the `honkio` guard instead of `honkio.auth`: `Route::middleware('auth:honkio')`, and `Auth::guard('honkio')->user()` is a `Honkio\Laravel\Auth\HonkioUser` with the ID token's claims (`sub`, `email`, `org_id`, `org_role`, ...).

**Outages.** Users already signed in stay signed in while Honkio is down: once their access token (10 minutes) expires, the middleware and guard try to refresh it, and if Honkio can't be reached (a connection error, a timeout or a 5xx) they let the request through on the old tokens, with `$request->attributes->get('honkio_auth_offline')` set, for at most 24 hours past the expiry. A 429 or 408 from Honkio lets that request through on the old tokens too (without `honkio_auth_offline`, since Honkio is up), and the next request asks again. A refresh Honkio refuses (the session was revoked, the user blocked or deleted, or removed from the organization) signs the user out at once: the tokens are forgotten, the guard named in `HONKIO_AUTH_LOGOUT_GUARD` (your own, such as `web`) is logged out, and the browser is sent to the `login` route (JSON requests get 401). Once 3 different sessions in a row can't reach Honkio within 10 seconds, refreshes for that app skip Honkio for 15 seconds and let requests through the same way, so they don't each wait on a timeout; the pause is kept in the cache store, per app, so every worker sees it and your other apps don't. New sign-ins need Honkio to be up.

Refresh tokens rotate and reusing one ends the session, so refreshes take a cache lock per session, and a request that waited on another's refresh reads the new tokens from that session's entry in the cache (kept 30 seconds, readable only with the same session id, deleted at sign-out). Use a cache store every worker shares (`HONKIO_AUTH_CACHE_STORE`, Redis or the database) so the lock, that entry and the cached signing keys are shared. Cached values are encrypted with your app key.

To check bearer tokens in an API, or to manage apps, users and organizations, see the PHP SDK's [Honkio Auth section](https://honkio.ca/docs/sdk): `app(Honkio\Auth\HonkioAuth::class)->verifyAccessToken($token)` uses the same settings, and `Honkio::authApps()` the API key. For example, `Honkio::authApps()->users->passkeys($appId, $userId)` lists a user's passkeys and `Honkio::authApps()->users->removePasskey($appId, $userId, $passkeyId)` removes one (the user is told, and their sessions end).

For a second app, build another provider: `Socialite::buildProvider(Honkio\Laravel\Socialite\HonkioProvider::class, $config)->forIssuer('https://login.other.ca')`.

## Retries

The client sends a call again, after the wait the API asks for, when the API refused it only because it should wait: `NUMBER_RATE_LIMITED` (the sending number already has a minute of message parts waiting at the carrier), `CARRIER_THROTTLED` and `CARRIER_UNAVAILABLE` (nothing was sent or charged), and `RATE_LIMITED` or a connection failure on a GET or a send with an idempotency key. `CARRIER_TIMEOUT` (the message may have gone out) and the daily, per-recipient and pause limits are never retried. By default it retries at most 2 times and does not wait out a wait longer than 60 seconds; then the exception is thrown as usual, and the queue's own retries apply.

Each wait blocks the worker. On a queue that retries jobs itself, you can turn the client's retries off with `HONKIO_MAX_RETRIES=0`, or lower `HONKIO_MAX_RETRY_DELAY`. Messages and email always carry an idempotency key, so the client's retries never send twice.

## Developing

This package lives in the HonkIO monorepo and is tested against the PHP SDK on the same commit:

```sh
cd sdk/laravel
bin/use-local-php-sdk.sh   # adds a path repository for ../php to composer.json; do not commit that change
composer update
vendor/bin/phpunit
```

The script is idempotent, and CI runs it before installing. `composer.lock` is not committed, so use `composer update` locally: `composer install` against a lock from an older checkout skips dependencies added since (for example `laravel/socialite`, which the Socialite tests need).

## Testing your own code

Use Laravel's fakes: `Mail::fake()` for mail and `Notification::fake()` for the SMS channel. To test the real transport or channel against canned API answers, bind a `Honkio\Honkio` built with a Guzzle `MockHandler` client (`new Honkio\Honkio('key', ['http_client' => $client])`). `Honkio\Honkio` is `final`, so Mockery's `shouldReceive` does not work on it.

## License

MIT
