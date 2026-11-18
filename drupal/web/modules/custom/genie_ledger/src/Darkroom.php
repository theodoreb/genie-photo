<?php

declare(strict_types=1);

namespace Drupal\genie_ledger;

/**
 * Renders the shared picture: covers first, then the crop.
 */
final class Darkroom {

  private const COVER_COLOR = [43, 33, 64];

  /**
   * Returns the frame as PNG bytes.
   */
  public function render(string $jpeg, Session $session, Frame $frame): string {
    $image = imagecreatefromstring($jpeg);
    $cover = imagecolorallocate($image, ...self::COVER_COLOR);
    foreach ($session->regions as $region) {
      if ($region['kind'] === 'person' && ($session->wishes[$region['owner']] ?? NULL) === Operation::Cover->value) {
        imagefilledrectangle(
          $image,
          $region['x'],
          $region['y'],
          $region['x'] + $region['width'] - 1,
          $region['y'] + $region['height'] - 1,
          $cover,
        );
      }
    }
    $crop = imagecrop($image, [
      'x' => $frame->x,
      'y' => $frame->y,
      'width' => $frame->width,
      'height' => $frame->height,
    ]);
    ob_start();
    imagepng($crop);
    return ob_get_clean();
  }

}
