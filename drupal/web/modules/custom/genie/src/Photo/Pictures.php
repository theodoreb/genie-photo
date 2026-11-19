<?php

declare(strict_types=1);

namespace Drupal\genie\Photo;

use Drupal\genie\Game\Exports;
use Drupal\genie\Game\Ledger;
use Drupal\genie\Game\Session;

/**
 * Renders the photo and the shared picture for assistants and the web view.
 */
final class Pictures {

  private const TRACING = 'For each detected label, list the grid cells whose centre lies on that person: hair, hat, face, clothes, arms, hands and what they wear. Leave out the background, held objects, furniture and other people. Stay inside or close to the box. Where people overlap, give the cell to the one in front. Send them to scan_photo from front to back, each with its label as "detected", its cells as "outline" runs [row, first column, last column] and a head box in photo pixels. Leave out a box that holds no real person.';

  public function __construct(
    private readonly Ledger $ledger,
    private readonly PhotoStore $photos,
    private readonly Exports $exports,
    private readonly Darkroom $darkroom,
  ) {}

  /**
   * Returns an image for an assistant, with what it needs to read it.
   *
   * @return array{bytes: string, mime: string, info: array<string, mixed>}
   *   The JPEG and its description.
   *
   * @throws \InvalidArgumentException
   *   When that image does not exist yet.
   */
  public function image(int $uid, string $view): array {
    $session = $this->ledger->read();
    if ($session->photo === NULL) {
      throw new \InvalidArgumentException('There is no photo yet. Someone uploads it on the web view.');
    }
    $info = ['view' => $view, 'revision' => $session->revision, 'next' => $session->next($uid)];
    $bytes = match ($view) {
      'photo' => $this->photos->read(),
      'picture' => $this->pictureJpeg($session),
      'outline' => $this->darkroom->outlineView($this->photos->read(), $session),
      'my_body' => $this->bodyJpeg($session, $uid),
      default => throw new \InvalidArgumentException('The view is photo, picture, outline or my_body.'),
    };
    if ($view === 'photo') {
      $info += $session->photo + [
        'people' => self::people($session),
        'objects' => array_values($session->objectNames()),
      ];
    }
    if ($view === 'outline') {
      $info += [
        'columns' => Outlines::columns($session->photo),
        'rows' => Outlines::rows($session->photo),
        'cell_pixels' => Outlines::CELL,
        'detector' => $session->detection['status'] ?? 'off',
        'detected' => array_map(self::boxList(...), Places::detected($session)),
        'how_to' => self::TRACING,
      ];
    }
    if ($view === 'my_body') {
      $info['grid'] = 'Numbers name fine rows and columns of the whole photo. Dimmed cells are out of your reach.';
      $info['editable'] = Outlines::editable(Outlines::reach($session)["person-$uid"], $session->photo);
      $info['on_person'] = Outlines::editable(array_keys(Outlines::owners($session), "person-$uid", TRUE), $session->photo);
    }
    return ['bytes' => $bytes, 'mime' => 'image/jpeg', 'info' => $info];
  }

  /**
   * Returns an image the web view shows.
   *
   * @param string $view
   *   Either photo or frame.
   * @param mixed $revision
   *   For a frame, the revision the page asks for.
   *
   * @return array{bytes: string, mime: string, info: array<string, mixed>}
   *   The image and what to say about it.
   *
   * @throws \InvalidArgumentException
   *   When that image does not exist.
   */
  public function webPicture(string $view, mixed $revision): array {
    $session = $this->ledger->read();
    if ($session->photo === NULL) {
      throw new \InvalidArgumentException('The session has no photo yet.');
    }
    if ($view === 'photo') {
      return ['bytes' => $this->photos->read(), 'mime' => 'image/jpeg', 'info' => $session->photo];
    }
    if ($view !== 'frame') {
      throw new \InvalidArgumentException('The view is photo or frame.');
    }
    if ($revision !== $session->revision) {
      throw new \InvalidArgumentException('This picture is out of date.');
    }
    $export = $this->exports->current($session);
    if ($export === NULL) {
      throw new \InvalidArgumentException('No frame fits every wish right now.');
    }
    return [
      'bytes' => $export->png,
      'mime' => 'image/png',
      'info' => ['revision' => $revision, 'hash' => $export->hash],
    ];
  }

  /**
   * Renders the shared picture for an assistant.
   */
  private function pictureJpeg(Session $session): string {
    $export = $this->exports->current($session);
    if ($export === NULL) {
      throw new \InvalidArgumentException('No frame fits every wish right now.');
    }
    return $this->darkroom->toJpeg($export->png);
  }

  /**
   * Renders a player's whole person under the fine grid.
   */
  private function bodyJpeg(Session $session, int $uid): string {
    if (!isset($session->regions["person-$uid"])) {
      throw new \InvalidArgumentException(Places::NO_PLACE);
    }
    if (Outlines::reach($session)["person-$uid"] === []) {
      throw new \InvalidArgumentException(Places::NO_REACH);
    }
    return $this->darkroom->bodyView($this->photos->read(), $session, $uid);
  }

  /**
   * Writes a box as an assistant reads it.
   *
   * @param array{x: int, y: int, width: int, height: int} $box
   *   The box.
   *
   * @return array{int, int, int, int}
   *   The box as [x, y, width, height].
   */
  private static function boxList(array $box): array {
    return [$box['x'], $box['y'], $box['width'], $box['height']];
  }

  /**
   * Lists everyone found in the photo, claimed or not.
   *
   * @return list<array<string, mixed>>
   *   The people, with their boxes as [x, y, width, height].
   */
  private static function people(Session $session): array {
    $list = self::boxList(...);
    $people = [];
    foreach ($session->proposals as $id => $proposal) {
      $people[] = [
        'id' => $id,
        'description' => $proposal['label'],
        'head' => $list($proposal['head']),
        'body' => $list($proposal),
      ];
    }
    foreach ($session->regions as $region) {
      if ($region['kind'] === 'person') {
        $people[] = [
          'id' => $region['person'],
          'player' => $region['label'],
          'description' => $region['description'],
          'head' => $list($region['head']),
          'body' => $list($region),
        ];
      }
    }
    return $people;
  }

}
