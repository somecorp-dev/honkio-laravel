<?php

declare(strict_types=1);

namespace Honkio\Laravel\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Honkio\Honkio;
use Honkio\Laravel\HonkioServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Psr\Http\Message\RequestInterface;

abstract class TestCase extends Orchestra
{
    /** @var list<array{request: RequestInterface}> */
    protected array $history = [];

    protected MockHandler $mock;

    protected function getPackageProviders($app): array
    {
        return [HonkioServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('honkio.api_key', 'mk_test_laravel');
        $app['config']->set('honkio.sms_from', '+14165550100');
        $app['config']->set('mail.default', 'honkio');
        $app['config']->set('mail.mailers.honkio', ['transport' => 'honkio']);
        $app['config']->set('mail.from', ['address' => 'hello@example.ca', 'name' => 'Acme']);
    }

    /** Bind a real SDK client whose HTTP layer is a recording mock. */
    protected function fakeApi(Response|\Throwable ...$responses): void
    {
        $this->history = [];
        $this->mock = new MockHandler($responses);
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));

        $this->app->instance(
            Honkio::class,
            new Honkio('mk_test_laravel', ['http_client' => new Client(['handler' => $stack])]),
        );
        // The mail manager caches mailers; rebuild so it takes the bound client.
        $this->app['mail.manager']->forgetMailers();
    }

    /** @return array<string, mixed> JSON body of the nth recorded request. */
    protected function sentBody(int $n = 0): array
    {
        return json_decode((string) $this->history[$n]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function apiJson(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }
}
