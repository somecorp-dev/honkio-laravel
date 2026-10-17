# honkio/laravel

[HonkIO](https://honkio.ca) for Laravel: a mail transport and an SMS notification channel on top of [`honkio/honkio-php`](https://honkio.ca/docs/sdk). Canadian SMS and email, with CASL built in. Data is stored in Canada (ca-central-1).

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

## Retries

The client sends a call again, after the wait the API asks for, when the API refused it only because it should wait: `NUMBER_RATE_LIMITED` (the sending number already has a minute of message parts waiting at the carrier), `CARRIER_THROTTLED` and `CARRIER_UNAVAILABLE` (nothing was sent or charged), and `RATE_LIMITED` or a connection failure on a GET or a send with an idempotency key. `CARRIER_TIMEOUT` (the message may have gone out) and the daily, per-recipient and pause limits are never retried. By default it retries at most 2 times and does not wait out a wait longer than 60 seconds; then the exception is thrown as usual, and the queue's own retries apply.

Each wait blocks the worker. On a queue that retries jobs itself, you can turn the client's retries off with `HONKIO_MAX_RETRIES=0`, or lower `HONKIO_MAX_RETRY_DELAY`. Messages and email always carry an idempotency key, so the client's retries never send twice.

## Developing

This package lives in the HonkIO monorepo and is tested against the PHP SDK on the same commit:

```sh
cd sdk/laravel
bin/use-local-php-sdk.sh   # adds a path repository for ../php to composer.json; do not commit that change
composer install
vendor/bin/phpunit
```

The script is idempotent, and CI runs it before `composer install`.

## Testing your own code

Use Laravel's fakes: `Mail::fake()` for mail and `Notification::fake()` for the SMS channel. To test the real transport or channel against canned API answers, bind a `Honkio\Honkio` built with a Guzzle `MockHandler` client (`new Honkio\Honkio('key', ['http_client' => $client])`). `Honkio\Honkio` is `final`, so Mockery's `shouldReceive` does not work on it.

## License

MIT
