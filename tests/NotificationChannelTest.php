<?php

declare(strict_types=1);

namespace Honkio\Laravel\Tests;

use Honkio\Exception\InvalidArgumentException;
use Honkio\Laravel\Notifications\HonkioChannel;
use Honkio\Laravel\Notifications\HonkioMessage;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Str;

class NotificationChannelTest extends TestCase
{
    private function notifiable(?string $phone = '+16135550123', int $id = 7): object
    {
        return new class($phone, $id) {
            use Notifiable;

            public function __construct(public ?string $phone, private int $id)
            {
            }

            public function getKey(): int
            {
                return $this->id;
            }

            public function routeNotificationForHonkio(): ?string
            {
                return $this->phone;
            }
        };
    }

    private function notification(HonkioMessage|string $message, ?string $id = 'n-1'): Notification
    {
        $n = new class($message) extends Notification {
            public function __construct(private HonkioMessage|string $message)
            {
            }

            public function via($notifiable): array
            {
                return ['honkio'];
            }

            public function toHonkio($notifiable): HonkioMessage|string
            {
                return $this->message;
            }
        };
        $n->id = $id;

        return $n;
    }

    private function channel(): HonkioChannel
    {
        return $this->app->make(HonkioChannel::class);
    }

    public function test_sends_with_the_configured_from(): void
    {
        $this->fakeApi($this->apiJson(['id' => 'msg_1']));

        $result = $this->channel()->send($this->notifiable(), $this->notification(HonkioMessage::create('Your code is 123')));

        $this->assertSame(['id' => 'msg_1'], $result);
        $this->assertSame(
            ['from' => '+14165550100', 'to' => '+16135550123', 'body' => 'Your code is 123'],
            $this->sentBody(),
        );
        $this->assertSame('/v1/messages', $this->history[0]['request']->getUri()->getPath());
    }

    public function test_message_from_overrides_config_and_a_string_is_content(): void
    {
        $this->fakeApi($this->apiJson(['id' => 'msg_2']), $this->apiJson(['id' => 'msg_3']));

        $this->channel()->send($this->notifiable(), $this->notification(HonkioMessage::create('A')->from('+14165550199')));
        $this->channel()->send($this->notifiable(), $this->notification('B'));

        $this->assertSame('+14165550199', $this->sentBody(0)['from']);
        $this->assertSame('B', $this->sentBody(1)['body']);
        $this->assertSame('+14165550100', $this->sentBody(1)['from']);
    }

    public function test_no_route_means_no_call(): void
    {
        $this->fakeApi();

        $this->assertNull($this->channel()->send($this->notifiable(null), $this->notification('x')));
        $this->assertCount(0, $this->history);
    }

    public function test_no_sender_anywhere_names_the_env_var(): void
    {
        $this->fakeApi();
        $this->app['config']->set('honkio.sms_from', null);

        try {
            $this->channel()->send($this->notifiable(), $this->notification('x'));
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('HONKIO_SMS_FROM', $e->getMessage());
        }
        $this->assertCount(0, $this->history);
    }

    public function test_a_retry_sends_the_same_idempotency_key(): void
    {
        $this->fakeApi($this->apiJson(['id' => 'msg_a']), $this->apiJson(['id' => 'msg_a']));
        $notifiable = $this->notifiable();
        $notification = $this->notification('Hi', (string) Str::uuid());

        $this->channel()->send($notifiable, $notification);
        $this->channel()->send($notifiable, $notification);

        $keys = array_map(fn ($h) => $h['request']->getHeaderLine('Idempotency-Key'), $this->history);
        $this->assertNotSame('', $keys[0]);
        $this->assertSame($keys[0], $keys[1]);
        $this->assertSame(
            'laravel-notification-' . $notification->id . '-' . sha1(get_class($notifiable) . ':7'),
            $keys[0],
        );
    }

    public function test_different_recipients_get_different_keys(): void
    {
        $this->fakeApi($this->apiJson(['id' => 'm1']), $this->apiJson(['id' => 'm2']));
        $notification = $this->notification('Hi', 'same-id');

        $this->channel()->send($this->notifiable(id: 1), $notification);
        $this->channel()->send($this->notifiable(id: 2), $notification);

        $this->assertNotSame(
            $this->history[0]['request']->getHeaderLine('Idempotency-Key'),
            $this->history[1]['request']->getHeaderLine('Idempotency-Key'),
        );
    }

    public function test_works_through_the_notification_facade(): void
    {
        $this->fakeApi($this->apiJson(['id' => 'msg_f']));

        NotificationFacade::send($this->notifiable(), $this->notification('Hi', null));

        $this->assertCount(1, $this->history);
        $this->assertNotSame('', $this->history[0]['request']->getHeaderLine('Idempotency-Key'));
    }
}
