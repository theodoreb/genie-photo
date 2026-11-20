<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Keeps the web view's own Drupal token, which shows any game to anyone.
 */
final readonly class VisitorToken
{
    private const EARLY_REFRESH_SECONDS = 30;

    public function __construct(
        private DrupalOAuth $oauth,
        private CacheInterface $cache,
        #[Autowire('%env(GENIE_WEB_SECRET)%')]
        private string $clientSecret,
    ) {
    }

    public function accessToken(): string
    {
        // bin/start gives the client a new secret, also after a reinstall that voids the old tokens.
        return $this->cache->get('genie_visitor_token.'.hash('xxh128', $this->clientSecret), function (ItemInterface $item): string {
            $grant = $this->oauth->visitorToken();
            $item->expiresAfter($grant['expires_in'] - self::EARLY_REFRESH_SECONDS);

            return $grant['token'];
        });
    }
}
