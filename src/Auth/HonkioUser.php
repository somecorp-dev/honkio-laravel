<?php

declare(strict_types=1);

namespace Honkio\Laravel\Auth;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The signed-in user as the `honkio` guard returns it: the verified ID token
 * claims. $user->sub, $user->email, $user->org_id and any other claim read as
 * properties. There is no password or remember token.
 */
final class HonkioUser implements Authenticatable
{
    /** @param array<string, mixed> $claims */
    public function __construct(public readonly array $claims)
    {
    }

    public function __get(string $name): mixed
    {
        return $this->claims[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->claims[$name]);
    }

    /** The user's id (`sub`). */
    public function id(): string
    {
        return (string) ($this->claims['sub'] ?? '');
    }

    public function email(): ?string
    {
        $email = $this->claims['email'] ?? null;

        return is_string($email) ? $email : null;
    }

    /** The organization chosen at sign-in, when the app uses organizations. */
    public function organizationId(): ?string
    {
        $org = $this->claims['org_id'] ?? null;

        return is_string($org) ? $org : null;
    }

    public function organizationRole(): ?string
    {
        $role = $this->claims['org_role'] ?? null;

        return is_string($role) ? $role : null;
    }

    public function getAuthIdentifierName(): string
    {
        return 'sub';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->id();
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
