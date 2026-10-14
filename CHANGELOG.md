# Changelog

Versions are published to Packagist from a read-only mirror by hand; a version without a date is not published yet.

## 0.1.0

First release. Supports Laravel 12 and 13 on PHP 8.2 or newer (Laravel 11 is end of life and is not supported).

### Added

- `honkio` mail transport (Symfony Mailer) that sends through `POST /v1/emails`. Mail is transactional by default; the header `X-HonkIO-Commercial: true` marks a message commercial, and the API's refusals are thrown as the SDK's exceptions.
- `honkio` SMS notification channel with `HonkioMessage`, sending an idempotency key derived from the notification id and the recipient so a retried queued job never sends twice.
- `Honkio` facade and a shared `Honkio\Honkio` client built from `config/honkio.php` (`HONKIO_API_KEY`, `HONKIO_BASE_URL`, `HONKIO_SMS_FROM`), publishable under the tag `honkio-config`.
