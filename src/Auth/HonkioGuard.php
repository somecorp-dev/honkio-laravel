<?php

declare(strict_types=1);

namespace Honkio\Laravel\Auth;

use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;

/**
 * The `honkio` auth guard: the user is whoever signed in through HonkIO Auth
 * in this session, after the outage-tolerant refresh rule (see
 * {@see HonkioSession}). Use it as `auth:honkio` when your app keeps no users
 * table of its own; it is registered as auth.guards.honkio unless you define
 * that guard yourself.
 */
class HonkioGuard implements Guard
{
    use GuardHelpers;

    public function __construct(private readonly HonkioSession $session)
    {
    }

    public function user(): ?Authenticatable
    {
        if ($this->user !== null) {
            return $this->user;
        }
        $result = $this->session->ensureFresh();
        if ($result === null || !$result->isActive()) {
            return null;
        }

        return $this->user = new HonkioUser($this->session->claims() ?? []);
    }

    /**
     * There are no credentials to check: users sign in on the hosted page.
     *
     * @param array<string, mixed> $credentials
     */
    public function validate(array $credentials = []): bool
    {
        return false;
    }

    /** Signs out: forgets the HonkIO tokens for this session and revokes the refresh token at HonkIO. */
    public function logout(): void
    {
        $this->session->forget();
        $this->user = null;
    }

    /** True when this request runs on kept tokens because HonkIO can't be reached. */
    public function offline(): bool
    {
        return $this->session->offline();
    }
}
