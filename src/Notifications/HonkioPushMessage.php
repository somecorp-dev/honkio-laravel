<?php

declare(strict_types=1);

namespace Honkio\Laravel\Notifications;

final class HonkioPushMessage
{
    public ?string $appId = null;
    public ?string $title = null;
    public ?string $body = null;
    /** @var array<string, string>|null */
    public ?array $data = null;
    public ?string $userId = null;
    public ?string $deviceId = null;
    /** @var array{to: string, from: string, body: string}|null */
    public ?array $fallbackSms = null;
    public ?string $collapseKey = null;

    public static function create(): self
    {
        return new self();
    }

    /** The push app (its APNs and FCM credentials) to send with. Required. */
    public function app(string $appId): self
    {
        $this->appId = $appId;

        return $this;
    }

    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function body(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    /**
     * Extra string values delivered with the push; the keys are sent as given.
     *
     * @param array<string, string> $data
     */
    public function data(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    /** Send to every active device of this user. Defaults to what routeNotificationForHonkioPush() returns. */
    public function toUser(string $userId): self
    {
        $this->userId = $userId;
        $this->deviceId = null;

        return $this;
    }

    /** Send to one registered device instead of a user's devices. */
    public function toDevice(string $deviceId): self
    {
        $this->deviceId = $deviceId;
        $this->userId = null;

        return $this;
    }

    /**
     * A newer push with the same key replaces an older one not yet shown
     * (APNs apns-collapse-id, FCM collapse_key). 1 to 64 visible ASCII characters, no spaces.
     */
    public function collapseKey(string $collapseKey): self
    {
        $this->collapseKey = $collapseKey;

        return $this;
    }

    /** Send this text when no device accepts the push. It follows every rule a normal SMS does, and the API key needs messages:w. */
    public function fallbackSms(string $to, string $from, string $body): self
    {
        $this->fallbackSms = ['to' => $to, 'from' => $from, 'body' => $body];

        return $this;
    }
}
