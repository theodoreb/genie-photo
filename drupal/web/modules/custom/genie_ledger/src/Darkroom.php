<?php

declare(strict_types=1);

namespace Drupal\genie_ledger;

/**
 * Renders the shared picture: stickers, then covers, then the crop.
 */
final class Darkroom {

  private const COVER_COLOR = [43, 33, 64];

  /**
   * Returns the frame as PNG bytes.
   */
  public function render(string $jpeg, Session $session, Frame $frame): string {
    $image = imagecreatefromstring($jpeg);
    $people = array_filter($session->regions, static fn (array $region): bool => $region['kind'] === 'person');

    foreach ($people as $region) {
      if (isset($session->stickers[$region['owner']])) {
        $this->pasteSticker($image, $region, $session->stickers[$region['owner']]);
      }
    }
    $cover = imagecolorallocate($image, ...self::COVER_COLOR);
    foreach ($people as $region) {
      if (($session->wishes[$region['owner']] ?? NULL) === Operation::Cover->value) {
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

  /**
   * Draws a sticker inside its region.
   *
   * The blur runs on a copy of the region, so pixels from outside never enter
   * it, and only non-empty cells return to the photo.
   *
   * @param \GdImage $image
   *   The photo.
   * @param array{x: int, y: int, width: int, height: int} $region
   *   The player's region.
   * @param \Drupal\genie_ledger\Sticker $sticker
   *   The sticker.
   */
  private function pasteSticker(\GdImage $image, array $region, Sticker $sticker): void {
    $layer = imagecrop($image, [
      'x' => $region['x'],
      'y' => $region['y'],
      'width' => $region['width'],
      'height' => $region['height'],
    ]);
    $colors = array_map(
      static fn (string $color): int => imagecolorallocate($layer, ...sscanf($color, '#%02x%02x%02x')),
      $sticker->palette,
    );

    $cells = iterator_to_array($this->cells($region, $sticker), FALSE);
    foreach ($cells as [$cell, $x, $y, $width, $height]) {
      if ($cell !== Sticker::ORIGINAL) {
        imagefilledrectangle($layer, $x, $y, $x + $width - 1, $y + $height - 1, $colors[hexdec($cell)]);
      }
    }
    for ($pass = 0; $pass < $sticker->blur; $pass++) {
      imagefilter($layer, IMG_FILTER_GAUSSIAN_BLUR);
    }
    foreach ($cells as [, $x, $y, $width, $height]) {
      imagecopy($image, $layer, $region['x'] + $x, $region['y'] + $y, $x, $y, $width, $height);
    }
  }

  /**
   * Lists the non-empty cells that cover at least one pixel of the region.
   *
   * @param array{width: int, height: int} $region
   *   The player's region.
   * @param \Drupal\genie_ledger\Sticker $sticker
   *   The sticker.
   *
   * @return \Generator<array{string, int, int, int, int}>
   *   Cell value, then left, top, width and height inside the region.
   */
  private function cells(array $region, Sticker $sticker): \Generator {
    foreach ($sticker->rows as $rowIndex => $row) {
      $top = intdiv($rowIndex * $region['height'], Sticker::SIZE);
      $height = intdiv(($rowIndex + 1) * $region['height'], Sticker::SIZE) - $top;
      foreach (str_split($row) as $columnIndex => $cell) {
        $left = intdiv($columnIndex * $region['width'], Sticker::SIZE);
        $width = intdiv(($columnIndex + 1) * $region['width'], Sticker::SIZE) - $left;
        if ($cell !== Sticker::EMPTY && $width > 0 && $height > 0) {
          yield [$cell, $left, $top, $width, $height];
        }
      }
    }
  }

}
