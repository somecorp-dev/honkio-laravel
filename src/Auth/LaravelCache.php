<?php

declare(strict_types=1);

namespace Honkio\Laravel\Auth;

use Honkio\Auth\Cache;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;

/**
 * Honkio Auth's discovery, key and refresh cache on a Laravel cache store,
 * shared by every worker. Values are encrypted with the app key: each
 * session's last refresh is held here, tokens included, for 30 seconds
 * (see HonkioSession).
 */
final class LaravelCache implements Cache
{
    public function __construct(
        private readonly Repository $store,
        private readonly Encrypter $encrypter,
    ) {
    }

    public function store(): Repository
    {
        return $this->store;
    }

    public function get(string $key): ?array
    {
        $raw = $this->store->get($key);
        if (!is_string($raw)) {
            return null;
        }
        try {
            $value = json_decode((string) $this->encrypter->decrypt($raw, false), true, 64, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null; // another app key, or not ours
        }

        return is_array($value) ? $value : null;
    }

    public function set(string $key, array $value, int $ttlSeconds): void
    {
        $this->store->put($key, $this->encrypter->encrypt(json_encode($value, JSON_THROW_ON_ERROR), false), max(1, $ttlSeconds));
    }
}
