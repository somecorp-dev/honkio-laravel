# Changelog

Versions are published to Packagist from a read-only mirror by hand; a version without a date is not published yet.

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
