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
            // AnonymousNotifiable::getKey() returns null, so fall back to the
            // route: two anonymous recipients must not share a key.
            $id = method_exists($notifiable, 'getKey') ? $notifiable->getKey() : null;
            $who = $id !== null && $id !== '' ? (string) $id : (string) $to;
            $key = 'laravel-notification-' . $notification->id . '-' . sha1(get_class($notifiable) . ':' . $who);
        }

        $params = ['from' => $from, 'to' => (string) $to];
        if ($message->template !== null) {
            // The template's SMS part is the text; content is not sent with it.
            $params['template'] = $message->template;
            if ($message->variables !== []) {
                $params['variables'] = $message->variables;
            }
            if ($message->language !== null) {
                $params['language'] = $message->language;
            }
        } else {
            $params['body'] = $message->content;
        }

        return $this->client->messages->send($params, $key);
    }
}
