<?php

declare(strict_types=1);

namespace Honkio\Laravel\Tests;

use Honkio\Auth\HonkioAuth;
use Honkio\Honkio;
use Honkio\Laravel\Auth\HonkioSession;
use Honkio\Laravel\Auth\LaravelCache;
use Honkio\Laravel\Mail\HonkioTransport;
use Honkio\Laravel\Socialite\HonkioProvider;
use Illuminate\Support\Facades\Mail;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\SocialiteServiceProvider;

/** What the package builds, dumped or printed, never shows the API key, the client secret or the app key. */
class RedactionTest extends TestCase
{
    private const KEY = 'mk_live_supersecretkey0123456789';
    private const SECRET = 'hka_cs_supersecretclientsecret0123';

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), SocialiteServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('honkio.api_key', self::KEY);
        $app['config']->set('honkio.auth.client_secret', self::SECRET);
    }

    private function assertHidden(object $value, string ...$secrets): void
    {
        ob_start();
        var_dump($value);
        $dumped = (string) ob_get_clean();
        foreach ([$dumped, print_r($value, true), (string) json_encode($value)] as $shown) {
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $shown);
            }
        }
    }

    public function test_the_client_and_the_mail_transport_never_show_the_api_key(): void
    {
        $this->assertHidden($this->app->make(Honkio::class), self::KEY);
        $transport = Mail::mailer('honkio')->getSymfonyTransport();
        $this->assertInstanceOf(HonkioTransport::class, $transport);
        $this->assertHidden($transport, self::KEY);
    }

    public function test_the_auth_pieces_never_show_the_client_secret_or_the_app_key(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('needs ext-openssl');
        }
        $appKey = base64_decode(substr((string) $this->app['config']->get('app.key'), 7));
        $this->assertHidden($this->app->make(HonkioAuth::class), self::SECRET);
        $this->assertHidden($this->app->make(LaravelCache::class), $appKey);
        $this->app['session.store']->put('honkio_auth', ['access_token' => 'at_secret_in_session']);
        $this->assertHidden($this->app->make(HonkioSession::class), self::SECRET, $appKey, 'at_secret_in_session');

        $driver = Socialite::driver('honkio');
        $this->assertInstanceOf(HonkioProvider::class, $driver);
        $this->assertHidden($driver, self::SECRET);
        $this->assertStringContainsString(AuthFixture::CLIENT_ID, print_r($driver, true));
    }
}
