<?php

declare(strict_types=1);

namespace Honkio\Laravel;

use Honkio\Honkio;
use Honkio\Laravel\Mail\HonkioTransport;
use Honkio\Laravel\Notifications\HonkioChannel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Mail\MailManager;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\ServiceProvider;

class HonkioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/honkio.php', 'honkio');

        // Built on first resolve, so a missing key fails when you use HonkIO,
        // not when the application boots.
        $this->app->singleton(Honkio::class, function (Application $app): Honkio {
            /** @var array{api_key?: ?string, base_url?: ?string} $config */
            $config = $app['config']->get('honkio', []);
            $options = [];
            if (is_string($config['base_url'] ?? null) && $config['base_url'] !== '') {
                $options['base_url'] = $config['base_url'];
            }

            return new Honkio($config['api_key'] ?? null, $options);
        });

        $this->app->afterResolving(MailManager::class, function (MailManager $manager): void {
            $manager->extend('honkio', fn (array $config) => new HonkioTransport($this->app->make(Honkio::class)));
        });

        $this->app->afterResolving(ChannelManager::class, function (ChannelManager $manager): void {
            $manager->extend('honkio', fn ($app) => $app->make(HonkioChannel::class));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/honkio.php' => config_path('honkio.php')], 'honkio-config');
        }
    }
}
