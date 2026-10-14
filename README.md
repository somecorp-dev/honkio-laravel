# honkio/laravel

[HonkIO](https://honkio.ca) for Laravel: a mail transport and an SMS notification channel on top of [`honkio/honkio-php`](https://honkio.ca/docs/sdk). Canadian SMS and email, with CASL built in. Data is stored in Canada (ca-central-1).

- PHP 8.2 or newer, Laravel 11, 12 or 13 (auto-discovered, no manual provider registration)
- No automatic retries: a send costs money, so retrying is your choice. Notifications carry an idempotency key, so a retried queued job cannot send or charge twice. Queued mail retries are not deduplicated: this transport sends no idempotency key for email, so a job retried after a timeout can send twice.

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

`HONKIO_SMS_FROM` is the default sending number for SMS. `HONKIO_BASE_URL` is only for pointing at another API host. To edit the config, publish it:

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

If the API refuses a message (for example `EMAIL_COMMERCIAL_MULTI_RECIPIENT`, or no consent), the SDK exception is thrown from `Mail::send()`, so Laravel's normal failure handling applies. Queued mail is retried by the queue as usual.

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
```

`Honkio\Honkio` is bound as a singleton, so you can also type-hint it. See the [PHP SDK](https://honkio.ca/docs/sdk) for every resource.

## Developing

This package lives in the HonkIO monorepo and is tested against the PHP SDK on the same commit:

```sh
cd sdk/laravel
bin/use-local-php-sdk.sh   # adds a path repository for ../php to composer.json; do not commit that change
composer install
vendor/bin/phpunit
```

The script is idempotent, and CI runs it before `composer install`.

## License

MIT
