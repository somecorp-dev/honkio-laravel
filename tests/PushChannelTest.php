<?php

declare(strict_types=1);

namespace Honkio\Laravel\Tests;

use Honkio\Exception\InvalidArgumentException;
use Honkio\Laravel\Notifications\HonkioPushChannel;
use Honkio\Laravel\Notifications\HonkioPushMessage;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;

class PushChannelTest extends TestCase
{
    private function notifiable(?string $userId = 'u_7', int $id = 7): object
    {
        return new class($userId, $id) {
            use Notifiable;

            public function __construct(public ?string $userId, private int $id)
            {
            }

            public function getKey(): int
            {
                return $this->id;
            }

            public function routeNotificationForHonkioPush(): ?string
            {
                return $this->userId;
            }
        };
    }

    private function notification(HonkioPushMessage $message, ?string $id = 'n-1'): Notification
    {
        $n = new class($message) extends Notification {
            public function __construct(private HonkioPushMessage $message)
            {
            }

            public function via($notifiable): array
            {
                return ['honkio-push'];
            }

            public function toHonkioPush($notifiable): HonkioPushMessage
            {
                return $this->message;
            }
        };
        $n->id = $id;

        return $n;
    }

    private function channel(): HonkioPushChannel
    {
        return $this->app->make(HonkioPushChannel::class);
    }

    public function test_sends_to_the_routed_user_with_a_fallback_sms(): void
    {
        $this->fakeApi($this->apiJson(['id' => 'ps_1']));

        $result = $this->channel()->send($this->notifiable(), $this->notification(
            HonkioPushMessage::create()
                ->app('pa_1')
                ->title('Shipped')
                ->body('Your order is on its way')
                ->data(['orderId' => '42'])
                ->fallbackSms('+16135550123', '+14165550100', 'Your order shipped'),
        ));

        $this->assertSame(['id' => 'ps_1'], $result);
        $this->assertSame('/v1/push', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame(
            [
                'app_id' => 'pa_1',
                'to' => ['user_id' => 'u_7'],
                'title' => 'Shipped',
                'body' => 'Your order is on its way',
                'data' => ['orderId' => '42'],
                'fallback' => ['sms' => ['to' => '+16135550123', 'from' => '+14165550100', 'body' => 'Your order shipped']],
            ],
            $this->sentBody(),
        );
    }

    public function test_to_user_and_to_device_override_the_route(): void
    {
        $this->fakeApi($this->apiJson(['id' => 'ps_2']), $this->apiJson(['id' => 'ps_3']));

        $this->channel()->send($this->notifiable(), $this->notification(HonkioPushMessage::create()->app('pa_1')->body('x')->toUser('u_other')));
        $this->channel()->send($this->notifiable(), $this->notification(HonkioPushMessage::create()->app('pa_1')->body('x')->toDevice('pd_1')));

        $this->assertSame(['user_id' => 'u_other'], $this->sentBody(0)['to']);
        $this->assertSame(['device_id' => 'pd_1'], $this->sentBody(1)['to']);
    }

    public function test_no_route_means_no_call(): void
    {
        $this->fakeApi();

        $this->assertNull($this->channel()->send($this->notifiable(null), $this->notification(HonkioPushMessage::create()->app('pa_1')->body('x'))));
        $this->assertCount(0, $this->history);
    }

    public function test_a_message_without_an_app_names_the_method(): void
    {
        $this->fakeApi();

        try {
            $this->channel()->send($this->notifiable(), $this->notification(HonkioPushMessage::create()->body('x')));
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('->app()', $e->getMessage());
        }
        $this->assertCount(0, $this->history);
    }

    public function test_a_retry_sends_the_same_idempotency_key(): void
    {
        $this->fakeApi($this->apiJson(['id' => 'ps_a']), $this->apiJson(['id' => 'ps_a']));
        $notifiable = $this->notifiable();
        $notification = $this->notification(HonkioPushMessage::create()->app('pa_1')->body('x'), 'push-n-1');

        $this->channel()->send($notifiable, $notification);
        $this->channel()->send($notifiable, $notification);

        $keys = array_map(fn ($h) => $h['request']->getHeaderLine('Idempotency-Key'), $this->history);
        $this->assertSame($keys[0], $keys[1]);
        $this->assertSame('laravel-push-push-n-1-' . sha1(get_class($notifiable) . ':7'), $keys[0]);
    }

    public function test_works_through_the_notification_facade(): void
    {
        $this->fakeApi($this->apiJson(['id' => 'ps_f']));

        NotificationFacade::send($this->notifiable(), $this->notification(HonkioPushMessage::create()->app('pa_1')->title('Hi'), null));

        $this->assertCount(1, $this->history);
        $this->assertSame('/v1/push', $this->history[0]['request']->getUri()->getPath());
    }
}
