<?php

declare(strict_types=1);

namespace Honkio\Laravel\Notifications;

use Honkio\Exception\InvalidArgumentException;
use Honkio\Honkio;
use Illuminate\Notifications\Notification;

/**
 * Push notifications: `via()` returns 'honkio-push', the notification has a
 * `toHonkioPush($notifiable)` returning a HonkioPushMessage, and the notifiable
 * returns its user id from `routeNotificationForHonkioPush()`.
 */
class HonkioPushChannel
{
    public function __construct(private readonly Honkio $client)
    {
    }

    /**
     * @return array<mixed>|null The API's response, or null when there is nobody to push to.
     */
    public function send(mixed $notifiable, Notification $notification): ?array
    {
        $route = $notifiable->routeNotificationFor('honkio-push', $notification);

        $message = $notification->toHonkioPush($notifiable); // @phpstan-ignore-line
        if (!$message instanceof HonkioPushMessage) {
            throw new InvalidArgumentException('toHonkioPush() must return a HonkioPushMessage.');
        }

        // An explicit toUser() or toDevice() wins; otherwise the notifiable's route is the user id.
        $userId = $message->userId;
        if ($userId === null && $message->deviceId === null && $route !== null && $route !== '' && $route !== false) {
            $userId = (string) $route;
        }
        if ($userId === null && $message->deviceId === null) {
            return null;
        }
        if ($message->appId === null || $message->appId === '') {
            throw new InvalidArgumentException('No push app: call ->app() on the HonkioPushMessage.');
        }

        $params = ['app_id' => $message->appId, 'to' => $userId !== null ? ['user_id' => $userId] : ['device_id' => $message->deviceId]];
        if ($message->title !== null) {
            $params['title'] = $message->title;
        }
        if ($message->body !== null) {
            $params['body'] = $message->body;
        }
        if ($message->data !== null) {
            $params['data'] = $message->data;
        }
        if ($message->fallbackSms !== null) {
            $params['fallback'] = ['sms' => $message->fallbackSms];
        }

        // The same notification and recipient always give the same key, so a
        // queued job retried after a timeout cannot send or charge twice.
        $key = null;
        if (!empty($notification->id)) {
            $id = method_exists($notifiable, 'getKey') ? $notifiable->getKey() : null;
            $who = $id !== null && $id !== '' ? (string) $id : (string) ($userId ?? $message->deviceId);
            $key = 'laravel-push-' . $notification->id . '-' . sha1(get_class($notifiable) . ':' . $who);
        }

        return $this->client->push->send($params, $key);
    }
}
