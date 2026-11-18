<?php

declare(strict_types=1);

namespace App\Security;

/**
 * The OAuth tokens of one signed-in player.
 */
final readonly class TokenSet
{
    public function __construct(
        public string $clientId,
        public string $accessToken,
        public string $refreshToken,
        public int $expiresAt,
    ) {
    }
}
