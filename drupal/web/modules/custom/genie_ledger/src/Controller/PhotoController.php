<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\genie_ledger\Ledger;
use Drupal\genie_ledger\PersonDetector;
use Drupal\genie_ledger\PhotoIntake;
use Drupal\genie_ledger\PhotoStore;
use Drupal\genie_ledger\Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Takes in the photo over HTTP, because an upload is too large for a tool call.
 */
final class PhotoController extends ControllerBase {

  private const MAX_BYTES = 25_000_000;

  public function __construct(
    private readonly Ledger $ledger,
    private readonly PhotoIntake $intake,
    private readonly PhotoStore $photos,
    private readonly PersonDetector $detector,
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
    $name = $this->currentUser()->getAccountName();
    $session = $this->ledger->write(function (Session $session) use ($photo, $uploader, $name): void {
      if ($session->surveyRevision !== NULL) {
        throw new ConflictHttpException('This session already has its photo.');
      }
      $session->uploader = $uploader;
      $session->players[$uploader] = $name;
      $session->pin($this->photos->create($photo['jpeg'], $uploader, $this->detector->available() ? 'pending' : 'off'));
    });
    if ($session->survey->detector === 'pending') {
      $this->detector->launch();
    }

    return new JsonResponse(['revision' => $session->revision], 201);
  }

}
