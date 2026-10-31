<?php

declare(strict_types=1);

namespace Honkio\Laravel\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Honkio\Auth\DeletableCache;
use Honkio\Auth\HonkioAuth;
use Honkio\Auth\TokenSet;
use Honkio\Laravel\Auth\LaravelCache;
use Honkio\Laravel\Facades\Honkio as HonkioFacade;

/**
 * Honkio Auth pieces the Laravel package exposes from the PHP SDK: the users
 * resource's passkey routes through the facade, and the Laravel cache, which
 * holds the outage pause (shared by every worker, one per app) and deletes
 * the refresh memo when a session is revoked.
 */
class AuthResourcesTest extends TestCase
{
    public function test_the_facade_lists_and_removes_a_users_passkeys(): void
    {
        $passkeys = ['data' => [['id' => 'pk_1', 'name' => 'iPhone', 'usable' => true]]];
        $this->fakeApi($this->apiJson($passkeys), new Response(204));

        $this->assertSame($passkeys, HonkioFacade::authApps()->users->passkeys('app_1', 'usr_1'));
        $this->assertNull(HonkioFacade::authApps()->users->removePasskey('app_1', 'usr_1', 'pk_1'));

        $this->assertSame('GET', $this->history[0]['request']->getMethod());
        $this->assertSame('/v1/auth-apps/app_1/users/usr_1/passkeys', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame('DELETE', $this->history[1]['request']->getMethod());
        $this->assertSame('/v1/auth-apps/app_1/users/usr_1/passkeys/pk_1', $this->history[1]['request']->getUri()->getPath());
    }

    public function test_the_facade_moves_an_app_to_a_new_hostname_and_cancels_the_move(): void
    {
        $moving = ['id' => 'app_1', 'hostname' => 'login.acme.ca', 'pending_hostname' => 'auth.acme.ca'];
        $this->fakeApi($this->apiJson($moving), $this->apiJson(['id' => 'app_1', 'pending_hostname' => null]));

        $this->assertSame($moving, HonkioFacade::authApps()->update('app_1', ['hostname' => 'auth.acme.ca']));
        $this->assertNull(HonkioFacade::authApps()->cancelHostnameChange('app_1')['pending_hostname']);

        $this->assertSame('PATCH', $this->history[0]['request']->getMethod());
        $this->assertSame(['hostname' => 'auth.acme.ca'], $this->sentBody(0));
        $this->assertSame('DELETE', $this->history[1]['request']->getMethod());
        $this->assertSame('/v1/auth-apps/app_1/hostname-change', $this->history[1]['request']->getUri()->getPath());
    }

    public function test_the_facade_reads_the_latest_export_with_the_step_up(): void
    {
        $ready = ['id' => 'aex_1', 'status' => 'ready', 'download_url' => 'https://api.honkio.ca/v1/auth-exports/tok_2'];
        $this->fakeApi($this->apiJson($ready));

        $this->assertSame($ready, HonkioFacade::authApps()->exports->latest('app_1', 'su_1'));

        $this->assertSame('GET', $this->history[0]['request']->getMethod());
        $this->assertSame('/v1/auth-apps/app_1/exports/latest', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame('su_1', $this->history[0]['request']->getHeaderLine('X-Step-Up-Token'));
    }

    public function test_the_laravel_cache_deletes_entries(): void
    {
        $cache = $this->app->make(LaravelCache::class);
        $this->assertInstanceOf(DeletableCache::class, $cache);
        $cache->set('honkio-auth:test', ['a' => 1], 60);
        $this->assertSame(['a' => 1], $cache->get('honkio-auth:test'));
        $cache->delete('honkio-auth:test');
        $this->assertNull($cache->get('honkio-auth:test'));
    }

    /**
     * @param list<Response|\Throwable> $responses
     * @param list<mixed> $history
     */
    private function worker(string $issuer, array $responses, array &$history): HonkioAuth
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new HonkioAuth([
            'issuer' => $issuer,
            'client_id' => AuthFixture::CLIENT_ID,
            'client_secret' => 'shh',
            'http_client' => new Client(['handler' => $stack]),
            'cache' => $this->app->make(LaravelCache::class),
            'remember_refreshes' => false,
        ]);
    }

    private function expired(string $refreshToken): TokenSet
    {
        return new TokenSet('old.access.token', $refreshToken, 'old.id.token', time() - 5, 'usr_1');
    }

    public function test_the_outage_pause_is_shared_by_workers_of_one_app_and_by_no_other_app(): void
    {
        $down = fn (): ConnectException => new ConnectException('Connection refused', new Request('POST', AuthFixture::ISSUER . '/token'));
        $issuer = new AuthFixture();

        $history = [];
        $first = $this->worker(AuthFixture::ISSUER, [$issuer->discovery(), $down(), $down(), $down()], $history);
        foreach (['a', 'b', 'c'] as $s) {
            $this->assertTrue($first->refresh($this->expired("hka_rt_$s"))->isOffline());
        }

        // Another worker of the same app, on the same cache store: paused, no request.
        $secondHistory = [];
        $second = $this->worker(AuthFixture::ISSUER, [], $secondHistory);
        $this->assertTrue($second->refresh($this->expired('hka_rt_d'))->isOffline());
        $this->assertCount(0, $secondHistory);

        // Another app on the same cache store: not paused.
        $otherHistory = [];
        $other = $this->worker('https://login.other.ca', [new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'issuer' => 'https://login.other.ca',
            'authorization_endpoint' => 'https://login.other.ca/authorize',
            'token_endpoint' => 'https://login.other.ca/token',
            'jwks_uri' => 'https://login.other.ca/.well-known/jwks.json',
        ])), $down()], $otherHistory);
        $other->refresh($this->expired('hka_rt_e'));
        $this->assertCount(2, $otherHistory);
    }
}
