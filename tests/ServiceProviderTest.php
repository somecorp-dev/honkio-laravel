<?php

declare(strict_types=1);

namespace Honkio\Laravel\Tests;

use Honkio\Exception\InvalidArgumentException;
use Honkio\Honkio;
use Honkio\Laravel\Facades\Honkio as HonkioFacade;
use Honkio\Laravel\HonkioServiceProvider;
use Honkio\Laravel\Mail\HonkioTransport;
use Honkio\Laravel\Notifications\HonkioChannel;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

class ServiceProviderTest extends TestCase
{
    public function test_client_is_a_singleton_built_from_config(): void
    {
        $a = $this->app->make(Honkio::class);
        $this->assertInstanceOf(Honkio::class, $a);
        $this->assertSame($a, $this->app->make(Honkio::class));
    }

    public function test_missing_key_throws_on_first_resolve_not_on_boot(): void
    {
        // The application booted with a key; now remove it and rebuild.
        $this->app['config']->set('honkio.api_key', null);
        $this->app->forgetInstance(Honkio::class);
        putenv('HONKIO_API_KEY');

        $this->expectException(InvalidArgumentException::class);
        $this->app->make(Honkio::class);
    }

    public function test_booting_without_a_key_does_not_throw(): void
    {
        $this->app['config']->set('honkio.api_key', null);
        $this->app->forgetInstance(Honkio::class);
        $this->app->register(HonkioServiceProvider::class, true);
        $this->assertTrue($this->app->isBooted());
    }

    public function test_facade_proxies_to_the_client(): void
    {
        $this->assertSame($this->app->make(Honkio::class), HonkioFacade::getFacadeRoot());
        $this->assertSame($this->app->make(Honkio::class)->emails, HonkioFacade::emails());
        $this->assertSame($this->app->make(Honkio::class)->sendLimit, HonkioFacade::sendLimit());
    }

    public function test_the_facade_documents_every_resource_of_the_client(): void
    {
        $doc = (string) (new \ReflectionClass(HonkioFacade::class))->getDocComment();
        foreach ((new \ReflectionClass(Honkio::class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $line = sprintf('@method static \\%s %s()', (string) $property->getType(), $property->getName());
            $this->assertStringContainsString($line, $doc);
        }
    }

    public function test_config_is_merged_and_publishes_under_the_tag(): void
    {
        $this->assertSame('https://api.honkio.ca', $this->app['config']->get('honkio.base_url'));

        $paths = HonkioServiceProvider::pathsToPublish(HonkioServiceProvider::class, 'honkio-config');
        $this->assertCount(1, $paths);
        $this->assertStringEndsWith('config/honkio.php', array_key_first($paths));
    }

    public function test_honkio_auth_settings_are_merged_and_built_on_first_use(): void
    {
        $this->assertSame(['openid', 'email'], $this->app['config']->get('honkio.auth.scopes'));
        $this->assertSame('login', $this->app['config']->get('honkio.auth.login_route'));

        $auth = $this->app->make(\Honkio\Auth\HonkioAuth::class);
        $this->assertSame('https://login.example.ca', $auth->issuer());
        $this->assertSame($auth, $this->app->make(\Honkio\Auth\HonkioAuth::class));
    }

    public function test_booting_without_honkio_auth_settings_does_not_throw(): void
    {
        $this->app['config']->set('honkio.auth', []);
        $this->app->forgetInstance(\Honkio\Auth\HonkioAuth::class);
        $this->app->register(HonkioServiceProvider::class, true);
        $this->assertTrue($this->app->isBooted());

        $this->expectException(InvalidArgumentException::class);
        $this->app->make(\Honkio\Auth\HonkioAuth::class);
    }

    public function test_retry_settings_reach_the_client(): void
    {
        $this->assertSame(2, $this->app['config']->get('honkio.max_retries'));
        $this->assertSame(60, $this->app['config']->get('honkio.max_retry_delay'));

        // A string from the environment is a number to the client; a negative one is refused like the SDK's own option.
        $this->app['config']->set('honkio.max_retries', '-1');
        $this->app->forgetInstance(Honkio::class);
        $this->expectException(InvalidArgumentException::class);
        $this->app->make(Honkio::class);
    }

    public function test_mail_transport_and_channel_are_registered(): void
    {
        $this->assertInstanceOf(HonkioTransport::class, Mail::mailer('honkio')->getSymfonyTransport());
        $this->assertInstanceOf(HonkioChannel::class, Notification::driver('honkio'));
    }
}
