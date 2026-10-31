<?php

declare(strict_types=1);

namespace Honkio\Laravel\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Honkio\Auth\HonkioAuth;
use Honkio\Honkio;
use Honkio\Laravel\Auth\LaravelCache;
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
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('honkio.auth.issuer', AuthFixture::ISSUER);
        $app['config']->set('honkio.auth.client_id', AuthFixture::CLIENT_ID);
        $app['config']->set('honkio.auth.client_secret', 'shh');
        $app['config']->set('honkio.auth.redirect', 'https://app.example.ca/auth/honkio/callback');
    }

    /** @var list<array{request: RequestInterface}> */
    protected array $issuerHistory = [];

    /** Whether fakeIssuer() turns the token-keyed refresh memo on (the service provider leaves it off). */
    protected bool $issuerRemembersRefreshes = false;

    /**
     * Binds a HonkioAuth for the configured app whose HTTP calls (discovery,
     * keys, refresh, revoke) are answered in order from $responses.
     */
    protected function fakeIssuer(Response|\Throwable ...$responses): void
    {
        $this->issuerHistory = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->issuerHistory));
        $this->app->instance(HonkioAuth::class, new HonkioAuth([
            'issuer' => AuthFixture::ISSUER,
            'client_id' => AuthFixture::CLIENT_ID,
            'client_secret' => 'shh',
            'http_client' => new Client(['handler' => $stack]),
            'cache' => $this->app->make(LaravelCache::class),
            'remember_refreshes' => $this->issuerRemembersRefreshes, // false as the service provider binds it
        ]));
        $this->app->forgetScopedInstances();
        $this->app['auth']->forgetGuards();
    }

    /** Bind a real SDK client whose HTTP layer is a recording mock. */
    protected function fakeApi(Response|\Throwable ...$responses): void
    {
        $this->history = [];
        $this->mock = new MockHandler($responses);
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));

        // No retries: each queued response answers one call. Retrying is the PHP SDK's, tested there.
        $this->app->instance(
            Honkio::class,
            new Honkio('mk_test_laravel', ['http_client' => new Client(['handler' => $stack]), 'max_retries' => 0]),
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
