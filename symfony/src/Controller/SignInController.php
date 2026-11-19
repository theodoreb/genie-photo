<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\DrupalAuthenticator;
use App\Security\DrupalOAuth;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class SignInController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET'])]
    public function login(Request $request, DrupalOAuth $oauth): RedirectResponse
    {
        $start = $oauth->start();
        $request->getSession()->set(DrupalAuthenticator::PENDING_KEY, $start);

        return new RedirectResponse($start['url']);
    }

    #[Route('/oauth/callback', name: 'app_oauth_callback', methods: ['GET'])]
    public function callback(): never
    {
        throw new \LogicException('DrupalAuthenticator answers this route.');
    }

    #[Route('/logout', name: 'app_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('The firewall answers this route.');
    }
}
