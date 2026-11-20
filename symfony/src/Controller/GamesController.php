<?php

declare(strict_types=1);

namespace App\Controller;

use App\Drupal\DrupalToolFailed;
use App\Drupal\McpDrupalTools;
use App\Security\VisitorToken;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Shows every game to anyone, through the web view's own Drupal token.
 */
final class GamesController extends AbstractController
{
    // A game's photo never changes. Its shared picture does, with every wish.
    private const MAX_AGE_SECONDS = ['photo' => 86400, 'frame' => 60];

    private const TYPES = ['photo' => 'image/jpeg', 'frame' => 'image/png'];

    // Drupal names why it refused a seat. Only these names show, so the address cannot put words on the page.
    private const REFUSED = [
        'over' => 'This game is over.',
        'full' => 'This game is full.',
        'name' => 'A name has 1 to 30 letters, digits, spaces, dots, dashes or apostrophes.',
        'taken' => 'Someone in this game already has this name.',
    ];

    public function __construct(
        private readonly McpDrupalTools $tools,
        private readonly VisitorToken $visitor,
        #[Autowire('%env(DRUPAL_URL)%/genie/host')]
        private readonly string $hostUrl,
    ) {
    }

    #[Route('/games', name: 'app_games', methods: ['GET'])]
    public function index(): Response
    {
        $games = $this->tools->call($this->visitor->accessToken(), 'web_games_list')['games'];

        return $this->render('games/index.html.twig', [
            'joinable' => array_filter($games, static fn (array $game): bool => null !== $game['join']),
            'playing' => array_filter($games, static fn (array $game): bool => null === $game['join'] && 'open' === $game['state']),
            'over' => array_filter($games, static fn (array $game): bool => 'open' !== $game['state']),
            'host_url' => $this->hostUrl,
        ]);
    }

    #[Route('/games/{game}', name: 'app_game', requirements: ['game' => Requirement::UUID], methods: ['GET'])]
    public function show(string $game, Request $request): Response
    {
        try {
            $view = $this->tools->call($this->visitor->accessToken(), 'web_game_view', ['game' => $game]);
        } catch (DrupalToolFailed $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }

        return $this->render('games/show.html.twig', ['game' => $view, 'error' => self::REFUSED[$request->query->getString('refused')] ?? null]);
    }

    #[Route('/games/{game}/{view}', name: 'app_game_picture', requirements: ['game' => Requirement::UUID, 'view' => 'photo|frame'], methods: ['GET'])]
    public function picture(string $game, string $view): Response
    {
        try {
            $bytes = $this->tools->image($this->visitor->accessToken(), 'web_game_picture', ['game' => $game, 'view' => $view]);
        } catch (DrupalToolFailed $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }

        return new Response($bytes, headers: [
            'Content-Type' => self::TYPES[$view],
            'Cache-Control' => 'public, max-age='.self::MAX_AGE_SECONDS[$view],
        ]);
    }
}
