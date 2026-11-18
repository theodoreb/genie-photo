<?php

declare(strict_types=1);

namespace App\Security;

use App\Drupal\DrupalToolFailed;
use App\Drupal\McpDrupalTools;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;

/**
 * Finishes the OAuth sign-in on the callback route.
 */
final class DrupalAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public const PENDING_KEY = 'oauth_pending';

    private ?TokenSet $tokens = null;

    public function __construct(
        private readonly DrupalOAuth $oauth,
        private readonly McpDrupalTools $tools,
        private readonly PlayerTokens $playerTokens,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function supports(Request $request): bool
    {
        return 'app_oauth_callback' === $request->attributes->get('_route');
    }

    public function authenticate(Request $request): Passport
    {
        $pending = $request->getSession()->remove(self::PENDING_KEY);
        $state = $request->query->getString('state');
        $code = $request->query->getString('code');
        if (null === $pending || '' === $code || !hash_equals($pending['state'], $state)) {
            throw new CustomUserMessageAuthenticationException('The sign-in was refused or has expired.');
        }

        try {
            $this->tokens = $this->oauth->exchange($pending['clientId'], $code, $pending['verifier']);
            $status = $this->tools->call($this->tokens->accessToken, 'my_status');
        } catch (HttpException|DrupalToolFailed $e) {
            throw new CustomUserMessageAuthenticationException('Drupal did not accept the sign-in.', previous: $e);
        }

        return new SelfValidatingPassport(
            new UserBadge($status['name'], static fn (): Player => new Player($status['uid'], $status['name'])),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $this->playerTokens->keep($this->tokens);

        return new RedirectResponse($this->urls->generate('app_home'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new Response($exception->getMessageKey(), Response::HTTP_FORBIDDEN, ['Content-Type' => 'text/plain']);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new RedirectResponse($this->urls->generate('app_login'));
    }
}
