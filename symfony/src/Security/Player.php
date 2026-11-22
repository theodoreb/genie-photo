<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A person signed in through Drupal.
 */
final readonly class Player implements UserInterface
{
    /**
     * @param non-empty-string $name
     */
    public function __construct(
        public int $uid,
        public string $name,
    ) {
    }

    public function getRoles(): array
    {
        return ['ROLE_PLAYER'];
    }

    public function getUserIdentifier(): string
    {
        return $this->name;
    }
}
