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
    public function home(Request $request, #[Autowire('%env(DRUPAL_URL)%/.well-known/mercure?match=genie/session')] string $hubUrl): Response
    {
        try {
            $session = $this->tools->call($this->tokens->accessToken(), 'session_get');
        } catch (DrupalToolFailed) {
            // Drupal no longer knows this sign-in, for instance after a reinstall.
            $request->getSession()->invalidate();

            return $this->redirectToRoute('app_login');
        }

        return $this->render('game/home.html.twig', ['session' => $session, 'hub_url' => $hubUrl]);
    }

    #[Route('/session', name: 'app_session', methods: ['GET'])]
    public function session(): JsonResponse
    {
        return $this->json($this->tools->call($this->tokens->accessToken(), 'session_get'));
    }

    #[Route('/photo', name: 'app_photo', methods: ['GET'])]
    public function photo(): Response
    {
        return $this->picture(['view' => 'photo'], 'image/jpeg');
    }

    #[Route('/frame/{revision}', name: 'app_frame', requirements: ['revision' => '\d+'], methods: ['GET'])]
    public function frame(int $revision): Response
    {
        return $this->picture(['view' => 'frame', 'revision' => $revision], 'image/png');
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

    #[Route('/proposals/dismiss', name: 'app_proposal_dismiss', methods: ['POST'])]
    #[IsCsrfTokenValid('regions', tokenKey: '_token')]
    public function dismissProposal(Request $request): JsonResponse
    {
        return $this->callTool('proposal_dismiss', ['proposal' => $request->request->getString('proposal')]);
    }

    #[Route('/approve', name: 'app_approve', methods: ['POST'])]
    #[IsCsrfTokenValid('regions', tokenKey: '_token')]
    public function approve(Request $request): JsonResponse
    {
        return $this->callTool('export_approve', ['hash' => $request->request->getString('hash')]);
    }

    #[Route('/objects/protect', name: 'app_protect', methods: ['POST'])]
    #[IsCsrfTokenValid('regions', tokenKey: '_token')]
    public function protect(Request $request): JsonResponse
    {
        return $this->callTool('protect_set', [
            'object' => $request->request->getString('object'),
            'protected' => $request->request->getBoolean('protected'),
        ]);
    }

    #[Route('/claim', name: 'app_claim', methods: ['POST'])]
    #[IsCsrfTokenValid('regions', tokenKey: '_token')]
    public function claim(Request $request): JsonResponse
    {
        return $this->callTool('claim_place', ['person' => $request->request->getString('person')]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function picture(array $arguments, string $type): Response
    {
        try {
            $bytes = $this->tools->image($this->tokens->accessToken(), 'picture_get', $arguments);
        } catch (DrupalToolFailed $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }

        return new Response($bytes, headers: ['Content-Type' => $type, 'Cache-Control' => 'private, no-store']);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function callTool(string $tool, array $arguments = []): JsonResponse
    {
        try {
            return $this->json($this->tools->call($this->tokens->accessToken(), $tool, $arguments));
        } catch (DrupalToolFailed $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
