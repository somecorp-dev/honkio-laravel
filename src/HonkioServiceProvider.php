<?php

declare(strict_types=1);

namespace Honkio\Laravel;

use Honkio\Auth\HonkioAuth;
use Honkio\Honkio;
use Honkio\Laravel\Auth\AuthConfig;
use Honkio\Laravel\Auth\HonkioGuard;
use Honkio\Laravel\Auth\HonkioSession;
use Honkio\Laravel\Auth\LaravelCache;
use Honkio\Laravel\Http\Middleware\EnsureHonkioSession;
use Honkio\Laravel\Mail\HonkioTransport;
use Honkio\Laravel\Notifications\HonkioChannel;
use Honkio\Laravel\Socialite\HonkioProvider;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Mail\MailManager;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\SocialiteManager;

class HonkioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/honkio.php', 'honkio');

        // Built on first resolve, so a missing key fails when you use HonkIO,
        // not when the application boots.
        $this->app->singleton(Honkio::class, function (Application $app): Honkio {
            /** @var array{api_key?: ?string, base_url?: ?string, max_retries?: mixed, max_retry_delay?: mixed} $config */
            $config = $app['config']->get('honkio', []);
            $options = [];
            if (is_string($config['base_url'] ?? null) && $config['base_url'] !== '') {
                $options['base_url'] = $config['base_url'];
            }
            // From env() these arrive as strings.
            if (is_numeric($config['max_retries'] ?? null)) {
                $options['max_retries'] = (int) $config['max_retries'];
            }
            if (is_numeric($config['max_retry_delay'] ?? null)) {
                $options['max_retry_delay'] = (float) $config['max_retry_delay'];
            }

            return new Honkio($config['api_key'] ?? null, $options);
        });

        $this->registerAuth();

        $this->callAfterResolving(MailManager::class, function (MailManager $manager): void {
            $manager->extend('honkio', fn (array $config) => new HonkioTransport($this->app->make(Honkio::class)));
        });

        $this->callAfterResolving(ChannelManager::class, function (ChannelManager $manager): void {
            $manager->extend('honkio', fn ($app) => $app->make(HonkioChannel::class));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/honkio.php' => config_path('honkio.php')], 'honkio-config');
        }

        $this->app->make(Router::class)->aliasMiddleware('honkio.auth', EnsureHonkioSession::class);
    }

    /**
     * Honkio Auth: the token checker and refresher for the configured app, the
     * session helper, the `honkio` guard driver and, when Socialite is
     * installed, the `honkio` Socialite driver. All of it is built on first
     * use, so an app that doesn't sign users in with Honkio needs no settings.
     */
    private function registerAuth(): void
    {
        $config = $this->app['config'];
        if (!$config->has('auth.guards.honkio')) {
            $config->set('auth.guards.honkio', ['driver' => 'honkio']);
        }

        $this->app->singleton(LaravelCache::class, function (Application $app): LaravelCache {
            $store = AuthConfig::resolve($app['config'])['cache_store'];

            return new LaravelCache($app['cache']->store($store), $app['encrypter']);
        });

        $this->app->singleton(HonkioAuth::class, function (Application $app): HonkioAuth {
            $settings = AuthConfig::resolve($app['config']);

            return new HonkioAuth([
                'issuer' => $settings['issuer'],
                'client_id' => $settings['client_id'],
                'client_secret' => $settings['client_secret'],
                'cache' => $app->make(LaravelCache::class),
                // HonkioSession serialises refreshes per session and shares the result with that session only.
                'remember_refreshes' => false,
            ]);
        });

        // One per request (and per Octane request): it remembers the request's refresh result.
        $this->app->scoped(HonkioSession::class, fn (Application $app): HonkioSession => new HonkioSession(
            $app->make(HonkioAuth::class),
            $app['session.store'],
            $app->make(LaravelCache::class),
        ));

        $this->callAfterResolving('auth', function (AuthManager $auth): void {
            $auth->extend('honkio', fn ($app) => new HonkioGuard($app->make(HonkioSession::class)));
        });

        if (interface_exists(SocialiteFactory::class)) {
            $this->callAfterResolving(SocialiteFactory::class, function (SocialiteFactory $socialite): void {
                if (!$socialite instanceof SocialiteManager) {
                    return;
                }
                $socialite->extend('honkio', function ($app) use ($socialite): HonkioProvider {
                    $settings = AuthConfig::resolve($app['config']);
                    /** @var HonkioProvider $provider */
                    $provider = $socialite->buildProvider(HonkioProvider::class, [
                        'client_id' => $settings['client_id'],
                        'client_secret' => $settings['client_secret'] ?? '',
                        'redirect' => $settings['redirect'],
                        'guzzle' => $settings['guzzle'],
                    ]);
                    // openid is what makes it OpenID Connect: always asked for.
                    $provider->setScopes(array_values(array_unique(['openid', ...($settings['scopes'] === [] ? ['email'] : $settings['scopes'])])));

                    return $provider->forIssuer((string) $settings['issuer'], $app->make(LaravelCache::class));
                });
            });
        }
    }
}
