<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AuthenticationExpiredException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;

/**
 * Holds the signed-in player's tokens in the session and keeps them fresh.
 */
final readonly class PlayerTokens
{
    private const SESSION_KEY = 'drupal_tokens';
    private const EARLY_REFRESH_SECONDS = 30;

    public function __construct(
        private RequestStack $requests,
        private DrupalOAuth $oauth,
    ) {
    }

    public function keep(TokenSet $tokens): void
    {
        $this->requests->getSession()->set(self::SESSION_KEY, $tokens);
    }

    /**
     * @throws AuthenticationExpiredException when Drupal no longer accepts the session, which sends the player to sign in
     */
    public function accessToken(): string
    {
        $tokens = $this->requests->getSession()->get(self::SESSION_KEY)
            ?? throw new AuthenticationExpiredException();

        if ($tokens->expiresAt - self::EARLY_REFRESH_SECONDS > time()) {
            return $tokens->accessToken;
        }

        try {
            $tokens = $this->oauth->refresh($tokens);
        } catch (HttpException $e) {
            throw new AuthenticationExpiredException(previous: $e);
        }
        $this->keep($tokens);

        return $tokens->accessToken;
    }
}
