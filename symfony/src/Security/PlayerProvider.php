<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Keeps the player from the session. Drupal owns the accounts.
 *
 * @implements UserProviderInterface<Player>
 */
final class PlayerProvider implements UserProviderInterface
{
    public function refreshUser(UserInterface $user): Player
    {
        if (!$user instanceof Player) {
            throw new UnsupportedUserException(\sprintf('Players only, not "%s".', $user::class));
        }

        return $user;
    }

    public function supportsClass(string $class): bool
    {
        return Player::class === $class;
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        throw new UserNotFoundException('Players sign in through Drupal.');
    }
}
