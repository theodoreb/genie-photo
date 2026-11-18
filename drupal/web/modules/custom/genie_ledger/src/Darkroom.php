<?php

declare(strict_types=1);

namespace Drupal\genie_ledger;

/**
 * Renders the shared picture: private areas, stickers, covers, then the crop.
 */
final class Darkroom {

  private const COVER_COLOR = [43, 33, 64];

  /**
   * Size of one sticker cell in the head view, in pixels.
   */
  private const GRID_CELL = 20;

  /**
   * Room for the cell numbers around the head view.
   */
  private const GRID_MARGIN = 40;

  /**
   * Cells between two numbered lines.
   */
  private const GRID_MAJOR = 4;

  /**
   * Returns the frame as PNG bytes.
   */
  public function render(string $jpeg, Session $session, Frame $frame): string {
    $crop = imagecrop($this->develop($jpeg, $session), [
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
   * Shows a player's head as the picture has it, under the sticker grid.
   *
   * @return string
   *   JPEG bytes. Cell 0, 0 starts after the margin that holds the numbers.
   */
  public function headView(string $jpeg, Session $session, int $uid): string {
    $area = self::stickerArea($session->regions["person-$uid"]['head'], $session->photo);
    $side = Sticker::SIZE * self::GRID_CELL;
    $head = imagescale($this->copyOf($this->develop($jpeg, $session), $area), $side, $side, IMG_BICUBIC);
    $view = imagecreatetruecolor($side + self::GRID_MARGIN, $side + self::GRID_MARGIN);
    imagefill($view, 0, 0, imagecolorallocate($view, 255, 255, 255));
    imagecopy($view, $head, self::GRID_MARGIN, self::GRID_MARGIN, 0, 0, $side, $side);

    $ink = imagecolorallocate($view, 0, 0, 0);
    $line = imagecolorallocatealpha($view, 255, 255, 255, 85);
    $major = imagecolorallocatealpha($view, 255, 225, 0, 25);
    for ($cell = 0; $cell <= Sticker::SIZE; $cell++) {
      $at = self::GRID_MARGIN + $cell * self::GRID_CELL;
      $color = $cell % self::GRID_MAJOR === 0 ? $major : $line;
      imageline($view, $at, self::GRID_MARGIN, $at, self::GRID_MARGIN + $side, $color);
      imageline($view, self::GRID_MARGIN, $at, self::GRID_MARGIN + $side, $at, $color);
      if ($cell % self::GRID_MAJOR === 0 && $cell < Sticker::SIZE) {
        imagestring($view, 4, $at + 2, 10, (string) $cell, $ink);
        imagestring($view, 4, 8, $at + 2, (string) $cell, $ink);
      }
    }
    return self::encodeJpeg($view);
  }

  /**
   * Converts a rendered picture for an assistant.
   */
  public function toJpeg(string $png): string {
    return self::encodeJpeg(imagecreatefromstring($png));
  }

  /**
   * Encodes an image as JPEG.
   */
  private static function encodeJpeg(\GdImage $image): string {
    ob_start();
    imagejpeg($image, NULL, 85);
    return ob_get_clean();
  }

  /**
   * Applies covers and stickers to the whole photo.
   */
  private function develop(string $jpeg, Session $session): \GdImage {
    $image = imagecreatefromstring($jpeg);
    $hidden = $this->hiddenHeads($session);

    // Hidden pixels go first, so no sticker blur can carry them elsewhere.
    $this->paintOver($image, $hidden);
    foreach ($session->regions as $id => $region) {
      if ($region['kind'] === 'person' && isset($session->stickers[$region['owner']])) {
        $others = [];
        foreach ($session->regions as $otherId => $other) {
          if ($otherId !== $id && $other['kind'] === 'person') {
            $others[] = $other['head'];
          }
        }
        $saved = array_map(fn (array $head): \GdImage => $this->copyOf($image, $head), $others);
        $this->pasteSticker($image, self::stickerArea($region['head'], $session->photo), $session->stickers[$region['owner']]);
        foreach ($others as $index => $head) {
          imagecopy($image, $saved[$index], $head['x'], $head['y'], 0, 0, $head['width'], $head['height']);
        }
      }
    }
    // A cover wins over any sticker on the same place.
    $this->paintOver($image, $hidden);
    return $image;
  }

  /**
   * Finds the square a sticker fills: the head box, centered and made square.
   *
   * @param array{x: int, y: int, width: int, height: int} $head
   *   The head box.
   * @param array{width: int, height: int} $photo
   *   The photo size.
   *
   * @return array{x: int, y: int, width: int, height: int}
   *   The square, inside the photo.
   */
  private static function stickerArea(array $head, array $photo): array {
    $side = min(max($head['width'], $head['height']), $photo['width'], $photo['height']);
    $x = $head['x'] + intdiv($head['width'] - $side, 2);
    $y = $head['y'] + intdiv($head['height'] - $side, 2);
    return [
      'x' => max(0, min($x, $photo['width'] - $side)),
      'y' => max(0, min($y, $photo['height'] - $side)),
      'width' => $side,
      'height' => $side,
    ];
  }

  /**
   * Lists the heads that must not show: covers, excluded, unclaimed people.
   *
   * @return list<array{x: int, y: int, width: int, height: int}>
   *   The head boxes.
   */
  private function hiddenHeads(Session $session): array {
    $private = [Operation::Cover->value, Operation::Exclude->value];
    $hidden = array_column($session->proposals, 'head');
    foreach ($session->regions as $region) {
      if ($region['kind'] === 'person' && in_array($session->wishes[$region['owner']] ?? NULL, $private, TRUE)) {
        $hidden[] = $region['head'];
      }
    }
    return $hidden;
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
   * Draws a sticker inside its square.
   *
   * The blur runs on a copy of the square, so pixels from outside never enter
   * it, and only non-empty cells return to the photo.
   *
   * @param \GdImage $image
   *   The photo.
   * @param array{x: int, y: int, width: int, height: int} $region
   *   The sticker's square.
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
   * Lists the non-empty cells that cover at least one pixel of the square.
   *
   * @param array{width: int, height: int} $area
   *   The sticker's square.
   * @param \Drupal\genie_ledger\Sticker $sticker
   *   The sticker.
   *
   * @return \Generator<array{string, int, int, int, int}>
   *   Cell value, then left, top, width and height inside the square.
   */
  private function cells(array $area, Sticker $sticker): \Generator {
    $side = $area['width'];
    foreach ($sticker->rows as $rowIndex => $row) {
      $top = intdiv($rowIndex * $side, Sticker::SIZE);
      $height = intdiv(($rowIndex + 1) * $side, Sticker::SIZE) - $top;
      foreach (str_split($row) as $columnIndex => $cell) {
        $left = intdiv($columnIndex * $side, Sticker::SIZE);
        $width = intdiv(($columnIndex + 1) * $side, Sticker::SIZE) - $left;
        if ($cell !== Sticker::EMPTY && $width > 0 && $height > 0) {
          yield [$cell, $left, $top, $width, $height];
        }
      }
    }
  }

}
