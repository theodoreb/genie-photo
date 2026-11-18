<?php

declare(strict_types=1);

namespace App\Controller;

use App\Drupal\DrupalPhotos;
use App\Drupal\DrupalToolFailed;
use App\Drupal\McpDrupalTools;
use App\Security\PlayerTokens;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

final class GameController extends AbstractController
{
    public function __construct(
        private readonly McpDrupalTools $tools,
        private readonly DrupalPhotos $photos,
        private readonly PlayerTokens $tokens,
    ) {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function home(#[Autowire('%env(DRUPAL_URL)%/.well-known/mercure?match=genie/session')] string $hubUrl): Response
    {
        return $this->render('game/home.html.twig', [
            'session' => $this->tools->call($this->tokens->accessToken(), 'session_get'),
            'hub_url' => $hubUrl,
        ]);
    }

    #[Route('/session', name: 'app_session', methods: ['GET'])]
    public function session(): JsonResponse
    {
        return $this->json($this->tools->call($this->tokens->accessToken(), 'session_get'));
    }

    #[Route('/photo', name: 'app_photo', methods: ['GET'])]
    public function photo(): Response
    {
        return new Response($this->photos->get($this->tokens->accessToken()), headers: [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    #[Route('/frame/{revision}', name: 'app_frame', requirements: ['revision' => '\d+'], methods: ['GET'])]
    public function frame(int $revision): Response
    {
        return new Response($this->photos->frame($this->tokens->accessToken(), $revision), headers: [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    #[Route('/photo', name: 'app_photo_upload', methods: ['POST'])]
    #[IsCsrfTokenValid('photo', tokenKey: '_token')]
    public function upload(Request $request): RedirectResponse
    {
        $photo = $request->files->get('photo');
        if (!$photo instanceof UploadedFile || !$photo->isValid()) {
            $this->addFlash('error', 'The photo did not arrive. It may be larger than 25 MB.');

            return $this->redirectToRoute('app_home');
        }

        try {
            $this->photos->put($this->tokens->accessToken(), $photo->getContent());
        } catch (DrupalToolFailed $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_home');
    }

    #[Route('/regions', name: 'app_region', methods: ['POST'])]
    #[IsCsrfTokenValid('regions', tokenKey: '_token')]
    public function region(Request $request): JsonResponse
    {
        $form = $request->request;
        try {
            return $this->json($this->tools->call($this->tokens->accessToken(), 'region_put', [
                'kind' => $form->getString('kind'),
                'label' => $form->getString('label'),
                'x' => $form->getInt('x'),
                'y' => $form->getInt('y'),
                'width' => $form->getInt('width'),
                'height' => $form->getInt('height'),
            ]));
        } catch (DrupalToolFailed $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
