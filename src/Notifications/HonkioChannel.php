<?php

declare(strict_types=1);

namespace Honkio\Laravel\Notifications;

use Honkio\Exception\InvalidArgumentException;
use Honkio\Honkio;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Notifications\Notification;

class HonkioChannel
{
    public function __construct(private readonly Honkio $client, private readonly Repository $config)
    {
    }

    /**
     * @return array<mixed>|null The API's response, or null when the notifiable has no route.
     */
    public function send(mixed $notifiable, Notification $notification): ?array
    {
        $to = $notifiable->routeNotificationFor('honkio', $notification);
        if ($to === null || $to === '' || $to === false) {
            return null;
        }

        $message = $notification->toHonkio($notifiable); // @phpstan-ignore-line
        if (is_string($message)) {
            $message = HonkioMessage::create($message);
        }
        if (!$message instanceof HonkioMessage) {
            throw new InvalidArgumentException('toHonkio() must return a HonkioMessage or a string.');
        }

        $from = $message->from ?? $this->config->get('honkio.sms_from');
        if (!is_string($from) || $from === '') {
            throw new InvalidArgumentException(
                'No sending number: call ->from() on the HonkioMessage or set HONKIO_SMS_FROM.'
            );
        }

        // The same notification and recipient always give the same key, so a
        // queued job retried after a timeout cannot send or charge twice.
        $key = null;
        if (!empty($notification->id)) {
            $who = method_exists($notifiable, 'getKey') ? (string) $notifiable->getKey() : (string) $to;
            $key = 'laravel-notification-' . $notification->id . '-' . sha1(get_class($notifiable) . ':' . $who);
        }

        return $this->client->messages->send(
            ['from' => $from, 'to' => (string) $to, 'body' => $message->content],
            $key,
        );
    }
}
