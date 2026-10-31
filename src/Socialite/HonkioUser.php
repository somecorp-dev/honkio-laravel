<?php

declare(strict_types=1);

namespace Honkio\Laravel\Socialite;

use Honkio\Auth\TokenSet;
use Laravel\Socialite\Two\User;

/**
 * What Socialite::driver('honkio')->user() returns: Socialite's user (id is
 * the HonkIO user id, `sub`), plus the verified ID token claims, the ID token
 * and the token set to keep with HonkioSession::login().
 */
class HonkioUser extends User
{
    /** @var array<string, mixed> the verified ID token claims, merged with userinfo */
    public array $claims = [];

    public ?string $idToken = null;

    private ?TokenSet $tokenSet = null;

    /** @param array<string, mixed> $claims */
    public function setHonkioTokens(TokenSet $tokens, array $claims): static
    {
        $this->tokenSet = $tokens;
        $this->idToken = $tokens->idToken;
        $this->claims = $claims;

        return $this;
    }

    public function tokenSet(): TokenSet
    {
        if ($this->tokenSet === null) {
            throw new \LogicException('This user did not come from the honkio Socialite driver.');
        }

        return $this->tokenSet;
    }
}
