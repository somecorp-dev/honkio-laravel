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
                ->collapseKey('order-42')
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
                'collapse_key' => 'order-42',
                'fallback' => ['sms' => ['to' => '+16135550123', 'from' => '+14165550100', 'body' => 'Your order shipped']],
            ],
            $this->sentBody(),
        );
    }

    public function test_badge_sound_thread_ttl_and_priority_are_sent_as_the_api_fields(): void
    {
        $this->fakeApi($this->apiJson(['id' => 'ps_4']));

        $this->channel()->send($this->notifiable(), $this->notification(
            HonkioPushMessage::create()
                ->app('pa_1')
                ->title('Reminder')
                ->badge(3)
                ->sound('default')
                ->threadId('appointments')
                ->ttl(3600)
                ->priority('normal'),
        ));

        $this->assertSame(
            [
                'app_id' => 'pa_1',
                'to' => ['user_id' => 'u_7'],
                'title' => 'Reminder',
                'badge' => 3,
                'sound' => 'default',
                'thread_id' => 'appointments',
                'ttl' => 3600,
                'priority' => 'normal',
            ],
            $this->sentBody(),
        );
    }

    public function test_unset_options_are_left_out_and_a_zero_badge_or_ttl_is_sent(): void
    {
        $this->fakeApi($this->apiJson(['id' => 'ps_5']), $this->apiJson(['id' => 'ps_6']));

        $this->channel()->send($this->notifiable(), $this->notification(HonkioPushMessage::create()->app('pa_1')->body('x')));
        $this->channel()->send($this->notifiable(), $this->notification(HonkioPushMessage::create()->app('pa_1')->body('x')->badge(0)->ttl(0)));

        $this->assertSame(['app_id', 'to', 'body'], array_keys($this->sentBody(0)));
        $this->assertSame(0, $this->sentBody(1)['badge']);
        $this->assertSame(0, $this->sentBody(1)['ttl']);
    }

    public function test_the_docs_push_samples_call_builder_methods_that_exist(): void
    {
        $file = __DIR__ . '/../../../web/lib/docs-samples/sdk-php.ts';
        if (!is_file($file)) {
            $this->markTestSkipped('reads web/lib/docs-samples from the HonkIO monorepo');
        }
        $source = (string) file_get_contents($file);
        $checked = 0;
        foreach (['laravelPushSend', 'laravelPushSendFallback'] as $name) {
            $this->assertSame(1, preg_match('/^ {2}' . $name . ': `((?:[^`\\\\]|\\\\.)*)`/m', $source, $m), "$name is in sdk-php.ts");
            $this->assertSame(1, preg_match('/HonkioPushMessage::create\(\)(.*?);/s', $m[1], $chain), "$name builds a HonkioPushMessage");
            preg_match_all('/->(\w+)\(/', $chain[1], $calls);
            foreach ($calls[1] as $method) {
                $this->assertTrue(method_exists(HonkioPushMessage::class, $method), "$name: HonkioPushMessage has no $method()");
                $checked++;
            }
        }
        $this->assertGreaterThan(0, $checked);
    }

    public function test_priority_accepts_only_high_or_normal(): void
    {
        $this->assertSame('high', HonkioPushMessage::create()->priority('high')->priority);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'urgent'");
        HonkioPushMessage::create()->priority('urgent');
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
