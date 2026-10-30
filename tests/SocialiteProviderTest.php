<?php

declare(strict_types=1);

namespace Honkio\Laravel\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Honkio\Exception\TokenVerificationException;
use Honkio\Laravel\Auth\HonkioSession;
use Honkio\Laravel\Socialite\HonkioProvider;
use Honkio\Laravel\Socialite\HonkioUser;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\SocialiteServiceProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Psr\Http\Message\RequestInterface;

/** The `honkio` Socialite driver: discovery, PKCE, state and nonce, and checked tokens. */
class SocialiteProviderTest extends TestCase
{
    private AuthFixture $issuer;

    /** @var list<array{request: RequestInterface}> */
    private array $calls = [];

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), SocialiteServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->issuer = new AuthFixture();
    }

    private function driver(Response ...$responses): HonkioProvider
    {
        $this->calls = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->calls));
        $driver = Socialite::driver('honkio');
        $this->assertInstanceOf(HonkioProvider::class, $driver);
        $driver->setHttpClient(new Client(['handler' => $stack]));

        return $driver;
    }

    /** A callback request whose session holds what redirect() stored. */
    private function returnToCallback(HonkioProvider $driver, string $state = 'st-1', string $nonce = 'n-1'): void
    {
        $session = $this->app['session.store'];
        $session->put('state', 'st-1');
        $session->put('code_verifier', 'verifier-1');
        $session->put(HonkioProvider::NONCE_SESSION_KEY, $nonce);
        $request = Request::create('/auth/honkio/callback', 'GET', ['code' => 'code-1', 'state' => $state]);
        $request->setLaravelSession($session);
        $driver->setRequest($request);
    }

    public function test_redirect_builds_an_oidc_authorize_url_with_pkce_state_and_nonce(): void
    {
        $driver = $this->driver($this->issuer->discovery());
        $request = Request::create('/login');
        $request->setLaravelSession($this->app['session.store']);
        $driver->setRequest($request);

        $url = $driver->redirect()->getTargetUrl();

        $this->assertStringStartsWith('https://login.example.ca/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('hka_client_1', $query['client_id']);
        $this->assertSame('https://app.example.ca/auth/honkio/callback', $query['redirect_uri']);
        $this->assertSame('openid email', $query['scope']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotEmpty($query['code_challenge']);
        $this->assertSame('en', $query['ui_locales']);

        $session = $this->app['session.store'];
        $this->assertSame($query['state'], $session->get('state'));
        $this->assertSame($query['nonce'], $session->get(HonkioProvider::NONCE_SESSION_KEY));
        $this->assertNotEmpty($session->get('code_verifier'));
        $this->assertSame('https://login.example.ca/.well-known/openid-configuration', (string) $this->calls[0]['request']->getUri());
    }

    public function test_redirect_asks_for_french_in_a_french_app(): void
    {
        $this->app->setLocale('fr_CA');
        $driver = $this->driver($this->issuer->discovery());
        $request = Request::create('/login');
        $request->setLaravelSession($this->app['session.store']);
        $driver->setRequest($request);

        parse_str((string) parse_url($driver->redirect()->getTargetUrl(), PHP_URL_QUERY), $query);
        $this->assertSame('fr', $query['ui_locales']);
    }

    public function test_user_exchanges_the_code_and_checks_the_tokens(): void
    {
        $driver = $this->driver(
            $this->issuer->discovery(),
            $this->issuer->tokenResponse(),
            $this->issuer->jwks(),
            AuthFixture::json(['sub' => 'usr_1', 'email' => 'ada@example.ca', 'name' => 'Ada']),
        );
        $this->returnToCallback($driver);

        $user = $driver->user();

        $this->assertInstanceOf(HonkioUser::class, $user);
        $this->assertSame('usr_1', $user->getId());
        $this->assertSame('ada@example.ca', $user->getEmail());
        $this->assertSame('Ada', $user->getName());
        $this->assertSame('hka_rt_new', $user->refreshToken);
        $this->assertSame(['otp'], $user->claims['amr']);
        $this->assertNotNull($user->idToken);
        $this->assertSame('usr_1', $user->tokenSet()->subject);

        parse_str((string) $this->calls[1]['request']->getBody(), $form);
        $this->assertSame('authorization_code', $form['grant_type']);
        $this->assertSame('code-1', $form['code']);
        $this->assertSame('verifier-1', $form['code_verifier']);
        $this->assertSame('shh', $form['client_secret']);
        $this->assertArrayNotHasKey('nonce', $form);
        $this->assertSame('Bearer ' . $user->token, $this->calls[3]['request']->getHeaderLine('Authorization'));

        // And the session keeps them, server-side.
        $this->app->make(HonkioSession::class)->login($user);
        $this->assertSame('usr_1', $this->app['session.store']->get(HonkioSession::SESSION_KEY)['claims']['sub']);
    }

    public function test_a_wrong_nonce_is_refused(): void
    {
        $driver = $this->driver($this->issuer->discovery(), $this->issuer->tokenResponse(), $this->issuer->jwks());
        $this->returnToCallback($driver, 'st-1', 'n-other');

        $this->expectException(TokenVerificationException::class);
        $driver->user();
    }

    public function test_a_wrong_state_is_refused_before_any_request(): void
    {
        $driver = $this->driver();
        $this->returnToCallback($driver, 'st-forged');

        $this->expectException(InvalidStateException::class);
        try {
            $driver->user();
        } finally {
            $this->assertCount(0, $this->calls);
        }
    }

    public function test_an_id_token_for_another_app_is_refused(): void
    {
        $driver = $this->driver(
            $this->issuer->discovery(),
            $this->issuer->tokenResponse(['id_token' => $this->issuer->idToken(['aud' => 'hka_client_2'])]),
            $this->issuer->jwks(),
        );
        $this->returnToCallback($driver);

        try {
            $driver->user();
            $this->fail('a token for another app passed');
        } catch (TokenVerificationException $e) {
            $this->assertSame('wrong_audience', $e->reason);
        }
    }

    public function test_userinfo_for_another_user_is_refused(): void
    {
        $driver = $this->driver(
            $this->issuer->discovery(),
            $this->issuer->tokenResponse(),
            $this->issuer->jwks(),
            AuthFixture::json(['sub' => 'usr_2']),
        );
        $this->returnToCallback($driver);

        $this->expectException(TokenVerificationException::class);
        $driver->user();
    }

    public function test_a_public_client_sends_no_secret(): void
    {
        $this->app['config']->set('honkio.auth.client_secret', null);
        $this->app->make(\Laravel\Socialite\Contracts\Factory::class)->forgetDrivers();
        $driver = $this->driver($this->issuer->discovery(), $this->issuer->tokenResponse(), $this->issuer->jwks(), AuthFixture::json([]));
        $this->returnToCallback($driver);
        $driver->user();

        parse_str((string) $this->calls[1]['request']->getBody(), $form);
        $this->assertArrayNotHasKey('client_secret', $form);
        $this->assertSame('hka_client_1', $form['client_id']);
    }

    public function test_services_honkio_overrides_the_package_config(): void
    {
        $this->app['config']->set('services.honkio', ['issuer' => 'https://login.other.ca', 'client_id' => 'hka_other']);
        $this->app->make(\Laravel\Socialite\Contracts\Factory::class)->forgetDrivers();
        $driver = Socialite::driver('honkio');
        $this->assertInstanceOf(HonkioProvider::class, $driver);
        $this->assertSame('https://login.other.ca', $driver->honkioAuth()->issuer());
        $this->assertSame('hka_other', $driver->honkioAuth()->clientId());
    }

    public function test_logout_url(): void
    {
        $driver = $this->driver($this->issuer->discovery());
        $this->assertSame(
            'https://login.example.ca/logout?client_id=hka_client_1&id_token_hint=a.b.c',
            $driver->logoutUrl('a.b.c'),
        );
    }
}
