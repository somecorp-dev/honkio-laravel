<?php

declare(strict_types=1);

namespace Honkio\Laravel\Http\Middleware;

use Closure;
use Honkio\Laravel\Auth\AuthConfig;
use Honkio\Laravel\Auth\HonkioSession;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;

/**
 * `honkio.auth`: the route needs a live HonkIO Auth session. It applies the
 * outage-tolerant refresh rule: while HonkIO can't be reached the request
 * goes through (and `$request->attributes->get('honkio_auth_offline')` is
 * true); once HonkIO refuses the refresh, or has been unreachable for 24
 * hours past the access token's expiry, the tokens are forgotten, the
 * `logout_guard` (if set) is logged out, and the request is answered like
 * Laravel's `auth` middleware: a redirect to `login_route`, or 401 for JSON.
 */
class EnsureHonkioSession
{
    public function __construct(
        private readonly HonkioSession $session,
        private readonly Repository $config,
        private readonly AuthFactory $auth,
        private readonly Router $router,
    ) {
    }

    /**
     * @param Closure(Request): Response $next
     *
     * @throws AuthenticationException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $result = $this->session->ensureFresh();
        if ($result === null || !$result->isActive()) {
            $settings = AuthConfig::resolve($this->config);
            if ($result !== null && $settings['logout_guard'] !== null) {
                $guard = $this->auth->guard($settings['logout_guard']);
                if ($guard instanceof StatefulGuard) {
                    $guard->logout();
                }
            }
            $route = $settings['login_route'];
            $redirect = !$request->expectsJson() && $route !== null && $this->router->has($route)
                ? route($route)
                : null;

            throw new AuthenticationException('Unauthenticated.', [], $redirect);
        }
        if ($this->session->offline()) {
            $request->attributes->set('honkio_auth_offline', true);
        }

        return $next($request);
    }
}
