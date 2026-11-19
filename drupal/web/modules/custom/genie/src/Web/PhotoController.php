<?php

declare(strict_types=1);

namespace Drupal\genie\Web;

use Drupal\Core\Controller\ControllerBase;
use Drupal\genie\Game\Ledger;
use Drupal\genie\Photo\PersonDetector;
use Drupal\genie\Photo\PhotoIntake;
use Drupal\genie\Photo\PhotoStore;
use Drupal\genie\Game\Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Takes in the photo over HTTP, because an upload is too large for a tool call.
 */
final class PhotoController extends ControllerBase {

  private const MAX_BYTES = 15_000_000;

  public function __construct(
    private readonly Ledger $ledger,
    private readonly PhotoIntake $intake,
    private readonly PhotoStore $photos,
    private readonly PersonDetector $detector,
  ) {}

  /**
   * Stores the game's photo, sent by its host, when it shows someone.
   */
  public function put(Request $request): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    $current = $this->ledger->stored();
    if ($current->uploader !== $uid) {
      throw new AccessDeniedHttpException('Only the player who started this game adds its photo.');
    }
    if ($current->surveyRevision !== NULL) {
      throw new ConflictHttpException('This game already has its photo.');
    }
    $bytes = stream_get_contents($request->getContent(TRUE), self::MAX_BYTES + 1);
    if (strlen($bytes) > self::MAX_BYTES) {
      throw new HttpException(413, sprintf('The image is larger than %d MB.', self::MAX_BYTES / 1_000_000));
    }
    try {
      $photo = $this->intake->normalize($bytes);
    }
    catch (\InvalidArgumentException $e) {
      throw new UnprocessableEntityHttpException($e->getMessage(), $e);
    }

    // A photo nobody is in is refused before anything is stored. Without a
    // detector, the assistants find the people.
    $status = 'off';
    $boxes = [];
    if ($this->detector->available()) {
      $boxes = $this->detector->detectInCommand($photo['jpeg']);
      $status = $boxes === NULL ? 'failed' : 'ready';
      if ($boxes === []) {
        throw new UnprocessableEntityHttpException('Nobody is in this photo. Choose a photo with people in it.');
      }
    }

    $name = $this->currentUser()->getDisplayName();
    $session = $this->ledger->write(function (Session $session) use ($photo, $uid, $name, $status, $boxes): void {
      if ($session->surveyRevision !== NULL) {
        throw new ConflictHttpException('This game already has its photo.');
      }
      $session->players[$uid] = $name;
      $session->transition = 'open';
      $session->pin($this->photos->create($photo['jpeg'], $uid, $status, $boxes ?? []));
    });
    return new JsonResponse(['revision' => $session->revision], 201);
  }

}
