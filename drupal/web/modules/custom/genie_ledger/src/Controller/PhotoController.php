<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\genie_ledger\Ledger;
use Drupal\genie_ledger\PhotoIntake;
use Drupal\genie_ledger\PhotoStore;
use Drupal\genie_ledger\Session;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Moves the photo in and out over HTTP, so pixels never enter an MCP tool.
 */
final class PhotoController extends ControllerBase {

  private const MAX_BYTES = 25_000_000;

  public function __construct(
    private readonly Ledger $ledger,
    private readonly PhotoIntake $intake,
    private readonly PhotoStore $photos,
  ) {}

  /**
   * Stores the session photo. The first player to upload is the uploader.
   */
  public function put(Request $request): JsonResponse {
    $bytes = stream_get_contents($request->getContent(TRUE), self::MAX_BYTES + 1);
    if (strlen($bytes) > self::MAX_BYTES) {
      throw new HttpException(413, 'The image is too large.');
    }
    try {
      $photo = $this->intake->normalize($bytes);
    }
    catch (\InvalidArgumentException $e) {
      throw new UnprocessableEntityHttpException($e->getMessage(), $e);
    }

    $uploader = (int) $this->currentUser()->id();
    $session = $this->ledger->write(function (Session $session) use ($photo, $uploader): void {
      if ($session->photo !== NULL) {
        throw new ConflictHttpException('This session already has its photo.');
      }
      $this->photos->save($photo['jpeg']);
      $session->uploader = $uploader;
      $session->photo = ['width' => $photo['width'], 'height' => $photo['height']];
    });

    return new JsonResponse(['revision' => $session->revision], 201);
  }

  /**
   * Sends the session photo to a signed-in player.
   */
  public function get(): BinaryFileResponse {
    if ($this->ledger->read()->photo === NULL) {
      throw new NotFoundHttpException();
    }
    return new BinaryFileResponse(PhotoStore::URI, 200, [
      'Content-Type' => 'image/jpeg',
      'Cache-Control' => 'private, no-store',
    ]);
  }

}
