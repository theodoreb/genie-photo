<?php

declare(strict_types=1);

namespace Drupal\genie_ledger;

/**
 * Renders the shared picture: private areas, stickers, covers, then the crop.
 */
final class Darkroom {

  private const COVER_COLOR = [43, 33, 64];

  /**
   * Returns the frame as PNG bytes.
   */
  public function render(string $jpeg, Session $session, Frame $frame): string {
    $image = imagecreatefromstring($jpeg);
    $hidden = $this->hiddenRegions($session);

    // Hidden pixels go first, so no sticker blur can carry them elsewhere.
    $this->paintOver($image, $hidden);
    foreach ($session->regions as $id => $region) {
      if ($region['kind'] === 'person' && isset($session->stickers[$region['owner']])) {
        $others = array_diff_key($session->regions, [$id => TRUE]);
        $saved = array_map(fn (array $other): \GdImage => $this->copyOf($image, $other), $others);
        $this->pasteSticker($image, $region, $session->stickers[$region['owner']]);
        foreach ($others as $otherId => $other) {
          imagecopy($image, $saved[$otherId], $other['x'], $other['y'], 0, 0, $other['width'], $other['height']);
        }
      }
    }
    // A cover wins over any sticker on the same place.
    $this->paintOver($image, $hidden);

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
   * Lists what must not show: covers, excluded players, unclaimed people.
   *
   * @return list<array{x: int, y: int, width: int, height: int}>
   *   The regions.
   */
  private function hiddenRegions(Session $session): array {
    $private = [Operation::Cover->value, Operation::Exclude->value];
    $hidden = array_filter($session->regions, static fn (array $region): bool => $region['kind'] === 'person'
      && in_array($session->wishes[$region['owner']] ?? NULL, $private, TRUE));
    $unclaimed = array_filter($session->proposals, static fn (array $box): bool => $box['kind'] === 'person');
    return [...array_values($hidden), ...array_values($unclaimed)];
  }

  /**
   * Fills regions with the opaque cover color.
   *
   * @param \GdImage $image
   *   The photo.
   * @param list<array{x: int, y: int, width: int, height: int}> $regions
   *   The regions.
   */
  private function paintOver(\GdImage $image, array $regions): void {
    $cover = imagecolorallocate($image, ...self::COVER_COLOR);
    foreach ($regions as $region) {
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

  /**
   * Copies a region of the photo.
   *
   * @param \GdImage $image
   *   The photo.
   * @param array{x: int, y: int, width: int, height: int} $region
   *   The region.
   */
  private function copyOf(\GdImage $image, array $region): \GdImage {
    return imagecrop($image, [
      'x' => $region['x'],
      'y' => $region['y'],
      'width' => $region['width'],
      'height' => $region['height'],
    ]);
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
    $layer = $this->copyOf($image, $region);
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
