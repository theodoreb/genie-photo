<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Drupal\McpDrupalTools;
use App\Security\DrupalAuthenticator;
use App\Security\DrupalOAuth;
use App\Security\PlayerTokens;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

final class DrupalAuthenticatorTest extends TestCase
{
    public function testRefusesACallbackWithAnotherState(): void
    {
        $clock = new MockClock();
        $oauth = new DrupalOAuth(
            new MockHttpClient(static fn (): never => throw new \LogicException('A refused callback reaches no server.')),
            $clock,
            'http://drupal.test',
            'http://web.test',
            'secret',
        );
        $session = new Session(new MockArraySessionStorage());
        $session->set(DrupalAuthenticator::PENDING_KEY, ['url' => 'http://drupal.test/authorize', 'state' => 'sent', 'verifier' => 'verifier']);
        $request = Request::create('/oauth/callback', parameters: ['state' => 'forged', 'code' => 'code']);
        $request->setSession($session);
        $authenticator = new DrupalAuthenticator(
            $oauth,
            new McpDrupalTools('http://drupal.test/mcp', new Psr16Cache(new ArrayAdapter())),
            new PlayerTokens(new RequestStack([$request]), $oauth, $clock),
            $this->createStub(UrlGeneratorInterface::class),
        );

        try {
            $authenticator->authenticate($request);
            self::fail('A callback with another state signed in.');
        } catch (CustomUserMessageAuthenticationException) {
        }
        // The pending sign-in is spent, so the right state cannot follow the forged one.
        self::assertFalse($session->has(DrupalAuthenticator::PENDING_KEY));
    }
}
