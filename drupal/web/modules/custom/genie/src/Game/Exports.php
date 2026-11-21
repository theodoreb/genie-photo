<?php

declare(strict_types=1);

namespace Drupal\genie\Game;

use Drupal\genie\Photo\Darkroom;
use Drupal\genie\Photo\PhotoStore;

/**
 * Renders the current picture once per revision and identifies it.
 */
final class Exports {

  public function __construct(
    private readonly PhotoStore $photos,
    private readonly Darkroom $darkroom,
  ) {}

  /**
   * Returns the current picture.
   */
  public function current(Session $session): Export {
    $export = $this->photos->export($session->revision);
    if ($export === NULL) {
      $png = $this->darkroom->render($this->photos->read(), $session);
      $export = new Export($png, $this->hash($png));
      $this->photos->saveExport($session->revision, $export);
    }
    return $export;
  }

  /**
   * Hashes the size and the decoded pixels, so PNG encoding cannot change it.
   */
  private function hash(string $png): string {
    $image = imagecreatefromstring($png);
    $width = imagesx($image);
    $height = imagesy($image);
    $context = hash_init('sha256');
    hash_update($context, pack('NN', $width, $height));
    for ($y = 0; $y < $height; $y++) {
      $row = '';
      for ($x = 0; $x < $width; $x++) {
        $row .= pack('N', imagecolorat($image, $x, $y));
      }
      hash_update($context, $row);
    }
    return hash_final($context);
  }

}
