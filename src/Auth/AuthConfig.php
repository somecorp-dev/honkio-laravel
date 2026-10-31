<?php

declare(strict_types=1);

namespace Honkio\Laravel\Auth;

use Illuminate\Contracts\Config\Repository;

/**
 * The HonkIO Auth settings: config('honkio.auth'), with any value set in
 * config('services.honkio') (the Socialite convention) taking precedence.
 *
 * @internal
 */
final class AuthConfig
{
    /**
     * @return array{issuer: ?string, client_id: ?string, client_secret: ?string, redirect: ?string, scopes: list<string>, cache_store: ?string, login_route: ?string, logout_guard: ?string, guzzle: array<string, mixed>}
     */
    public static function resolve(Repository $config): array
    {
        $base = $config->get('honkio.auth', []);
        $services = $config->get('services.honkio', []);
        $merged = array_merge(is_array($base) ? $base : [], array_filter(
            is_array($services) ? $services : [],
            static fn (mixed $v): bool => $v !== null && $v !== '',
        ));
        $string = static fn (mixed $v): ?string => is_string($v) && $v !== '' ? $v : null;
        $scopes = $merged['scopes'] ?? ['openid', 'email'];
        if (is_string($scopes)) {
            $scopes = preg_split('/[\s,]+/', $scopes, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        return [
            'issuer' => $string($merged['issuer'] ?? null),
            'client_id' => $string($merged['client_id'] ?? null),
            'client_secret' => $string($merged['client_secret'] ?? null),
            'redirect' => $string($merged['redirect'] ?? null),
            'scopes' => array_values(array_filter(is_array($scopes) ? $scopes : [], 'is_string')),
            'cache_store' => $string($merged['cache_store'] ?? null),
            'login_route' => $string($merged['login_route'] ?? null),
            'logout_guard' => $string($merged['logout_guard'] ?? null),
            'guzzle' => is_array($merged['guzzle'] ?? null) ? $merged['guzzle'] : [],
        ];
    }
}
