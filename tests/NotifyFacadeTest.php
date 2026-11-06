<?php

declare(strict_types=1);

namespace Honkio\Laravel\Tests;

use GuzzleHttp\Psr7\Response;
use Honkio\Laravel\Facades\Honkio as HonkioFacade;

/** Notify through the facade: the PHP SDK's notify, notifications and recipients resources. */
class NotifyFacadeTest extends TestCase
{
    public function test_the_facade_sends_a_notification_and_reads_the_log(): void
    {
        $sent = ['id' => 'ntf_1', 'status' => 'pending', 'attempts' => []];
        $this->fakeApi($this->apiJson($sent), $this->apiJson(['data' => []]));

        $this->assertSame($sent, HonkioFacade::notify()->send(['user_id' => 'user_42', 'text' => 'Your code is 123456'], 'n-1'));
        HonkioFacade::notifications()->list(['status' => 'failed']);

        $this->assertSame('POST', $this->history[0]['request']->getMethod());
        $this->assertSame('/v1/notify', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame('n-1', $this->history[0]['request']->getHeaderLine('Idempotency-Key'));
        $this->assertSame(['user_id' => 'user_42', 'text' => 'Your code is 123456'], $this->sentBody(0));
        $this->assertSame('/v1/notifications', $this->history[1]['request']->getUri()->getPath());
        $this->assertSame('status=failed', $this->history[1]['request']->getUri()->getQuery());
    }

    public function test_the_facade_keeps_a_recipient_profile(): void
    {
        $this->fakeApi($this->apiJson(['user_id' => 'user_42']), new Response(204));

        HonkioFacade::recipients()->upsert('user_42', ['email' => 'pat@example.com', 'language' => 'fr']);
        $this->assertNull(HonkioFacade::recipients()->delete('user_42'));

        $this->assertSame('PUT', $this->history[0]['request']->getMethod());
        $this->assertSame('/v1/recipients/user_42', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame(['email' => 'pat@example.com', 'language' => 'fr'], $this->sentBody(0));
        $this->assertSame('DELETE', $this->history[1]['request']->getMethod());
    }
}
