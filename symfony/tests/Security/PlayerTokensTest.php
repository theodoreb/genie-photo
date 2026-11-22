<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\DrupalOAuth;
use App\Security\PlayerTokens;
use App\Security\TokenSet;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class PlayerTokensTest extends TestCase
{
    public function testRefreshesATokenAboutToExpire(): void
    {
        $clock = new MockClock('2026-11-22 12:00:00');
        $refresh = new JsonMockResponse(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 300]);
        $http = new MockHttpClient([
            new JsonMockResponse(['token_endpoint' => 'http://drupal.test/oauth/token']),
            $refresh,
        ]);
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        $tokens = new PlayerTokens(
            new RequestStack([$request]),
            new DrupalOAuth($http, $clock, 'http://drupal.test', 'http://web.test', 'secret'),
            $clock,
        );
        $tokens->keep(new TokenSet('old-access', 'old-refresh', $clock->now()->getTimestamp() + 10));

        self::assertSame('new-access', $tokens->accessToken());
        self::assertStringContainsString('refresh_token=old-refresh', $refresh->getRequestOptions()['body']);
        self::assertEquals(
            new TokenSet('new-access', 'new-refresh', $clock->now()->getTimestamp() + 300),
            $session->get('drupal_tokens'),
        );
    }
}
