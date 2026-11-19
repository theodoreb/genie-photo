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
   * Outline cells between two numbers.
   */
  private const OUTLINE_LABEL_EVERY = 5;

  /**
   * Largest zoom of a person in their view.
   */
  private const BODY_VIEW_MAX_SCALE = 4;

  /**
   * Fewest sheet pixels between two grid numbers.
   */
  private const MIN_LABEL_GAP = 32;

  /**
   * Smallest cell side, in sheet pixels, that gets its own faint line.
   */
  private const MIN_FAINT_LINE = 8;

  private const PIXEL_ART_COLORS = 16;

  private const BOX_COLORS = [
    [230, 90, 0],
    [210, 0, 60],
    [0, 140, 70],
    [30, 90, 230],
    [160, 0, 180],
    [0, 140, 150],
    [120, 80, 0],
    [210, 0, 130],
  ];

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
    $owners = Outlines::owners($session);
    foreach ($session->regions as $id => $region) {
      if ($region['kind'] === 'person' && isset($session->paints[$region['owner']])) {
        $this->paintPerson($image, $session->photo, array_keys($owners, $id, TRUE), $session->paints[$region['owner']]);
      }
    }
    $traced = Outlines::owners($session, TRUE);
    foreach ($session->regions as $id => $region) {
      if ($region['kind'] === 'person' && isset($session->stickers[$region['owner']])) {
        $others = [];
        foreach ($session->regions as $otherId => $other) {
          if ($otherId !== $id && $other['kind'] === 'person') {
            $others[] = $other['head'];
          }
        }
        foreach ($traced as $cell => $owner) {
          if ($owner !== $id) {
            $others[] = Outlines::area($cell, $session->photo);
          }
        }
        $saved = array_map(fn (array $area): \GdImage => $this->copyOf($image, $area), $others);
        $this->pasteSticker($image, self::stickerArea($region['head'], $session->photo), $session->stickers[$region['owner']]);
        foreach ($others as $index => $area) {
          imagecopy($image, $saved[$index], $area['x'], $area['y'], 0, 0, $area['width'], $area['height']);
        }
      }
    }
    // A cover wins over any sticker on the same place.
    $this->paintOver($image, $hidden);
    return $image;
  }

  /**
   * Shows the photo under the outline grid, with the detector's boxes.
   *
   * @return string
   *   JPEG bytes. Cell 0, 0 starts after the margin that holds the numbers.
   */
  public function outlineView(string $jpeg, Session $session): string {
    $photo = $session->photo;
    $view = $this->framed(imagecreatefromstring($jpeg));
    $this->drawGrid($view, $photo['width'], $photo['height'], Outlines::CELL, 1, self::OUTLINE_LABEL_EVERY, 0, 0);

    imagesetthickness($view, 4);
    $ink = imagecolorallocate($view, 255, 255, 255);
    $number = 0;
    foreach (Places::detected($session) as $label => $box) {
      $color = imagecolorallocate($view, ...self::BOX_COLORS[$number++ % count(self::BOX_COLORS)]);
      $left = self::GRID_MARGIN + $box['x'];
      $top = self::GRID_MARGIN + $box['y'];
      imagerectangle($view, $left, $top, $left + $box['width'], $top + $box['height'], $color);
      imagefilledrectangle($view, $left, $top, $left + 30, $top + 20, $color);
      imagestring($view, 5, $left + 5, $top + 2, $label, $ink);
    }
    return self::encodeJpeg($view);
  }

  /**
   * Shows a player's person as the picture has it, under the fine grid.
   *
   * Cells the player cannot paint are dimmed.
   *
   * @return string
   *   JPEG bytes. The numbers name fine rows and columns of the whole photo.
   */
  public function bodyView(string $jpeg, Session $session, int $uid): string {
    $photo = $session->photo;
    $cells = array_keys(Outlines::owners($session), "person-$uid", TRUE);
    $bounds = self::bounds($cells, $photo);
    $image = $this->develop($jpeg, $session);
    $dim = imagecolorallocatealpha($image, 0, 0, 0, 45);
    $owned = array_flip($cells);
    $columns = Outlines::columns($photo);
    $cell = Outlines::CELL;
    for ($y = $bounds['y']; $y < $bounds['y'] + $bounds['height']; $y += $cell) {
      for ($x = $bounds['x']; $x < $bounds['x'] + $bounds['width']; $x += $cell) {
        if (!isset($owned[intdiv($y, $cell) * $columns + intdiv($x, $cell)])) {
          imagefilledrectangle($image, $x, $y, $x + $cell - 1, $y + $cell - 1, $dim);
        }
      }
    }
    $scale = max(1, min(self::BODY_VIEW_MAX_SCALE, intdiv(Outlines::LONG_SIDE, max($bounds['width'], $bounds['height']))));
    $width = $bounds['width'] * $scale;
    $height = $bounds['height'] * $scale;
    $view = $this->framed(imagescale($this->copyOf($image, $bounds), $width, $height, IMG_NEAREST_NEIGHBOUR));
    $this->drawGrid(
      $view,
      $width,
      $height,
      Outlines::FINE * $scale,
      Outlines::FINE_PER_CELL,
      Outlines::FINE_PER_CELL * (int) ceil(self::MIN_LABEL_GAP / ($cell * $scale)),
      intdiv($bounds['x'], Outlines::FINE),
      intdiv($bounds['y'], Outlines::FINE),
    );
    return self::encodeJpeg($view);
  }

  /**
   * Puts a picture on a white sheet with room for the grid numbers.
   */
  private function framed(\GdImage $picture): \GdImage {
    $view = imagecreatetruecolor(imagesx($picture) + self::GRID_MARGIN, imagesy($picture) + self::GRID_MARGIN);
    imagefill($view, 0, 0, imagecolorallocate($view, 255, 255, 255));
    imagecopy($view, $picture, self::GRID_MARGIN, self::GRID_MARGIN, 0, 0, imagesx($picture), imagesy($picture));
    return $view;
  }

  /**
   * Draws a numbered grid over a framed picture.
   *
   * @param \GdImage $view
   *   The sheet.
   * @param int $width
   *   The picture's width.
   * @param int $height
   *   The picture's height.
   * @param int $side
   *   The side of a grid cell in sheet pixels.
   * @param int $major
   *   Grid cells between two bright lines.
   * @param int $labelEvery
   *   Grid cells between two numbers.
   * @param int $firstColumn
   *   The number of the leftmost column.
   * @param int $firstRow
   *   The number of the top row.
   */
  private function drawGrid(\GdImage $view, int $width, int $height, int $side, int $major, int $labelEvery, int $firstColumn, int $firstRow): void {
    $ink = imagecolorallocate($view, 0, 0, 0);
    $faint = imagecolorallocatealpha($view, 255, 255, 255, 95);
    $bright = imagecolorallocatealpha($view, 255, 225, 0, 40);
    for ($line = 0; $line * $side <= $width; $line++) {
      $at = self::GRID_MARGIN + $line * $side;
      $number = $firstColumn + $line;
      if ($number % $major === 0 || $side >= self::MIN_FAINT_LINE) {
        imageline($view, $at, self::GRID_MARGIN, $at, self::GRID_MARGIN + $height, $number % $major === 0 ? $bright : $faint);
      }
      if ($number % $labelEvery === 0) {
        imagestring($view, 3, $at + 1, 14, (string) $number, $ink);
      }
    }
    for ($line = 0; $line * $side <= $height; $line++) {
      $at = self::GRID_MARGIN + $line * $side;
      $number = $firstRow + $line;
      if ($number % $major === 0 || $side >= self::MIN_FAINT_LINE) {
        imageline($view, self::GRID_MARGIN, $at, self::GRID_MARGIN + $width, $at, $number % $major === 0 ? $bright : $faint);
      }
      if ($number % $labelEvery === 0) {
        imagestring($view, 3, 6, $at + 1, (string) $number, $ink);
      }
    }
  }

  /**
   * Finds the rectangle around some cells.
   *
   * @param list<int> $cells
   *   The cell indexes.
   * @param array{width: int, height: int} $photo
   *   The photo size.
   *
   * @return array{x: int, y: int, width: int, height: int}
   *   The rectangle, inside the photo.
   */
  private static function bounds(array $cells, array $photo): array {
    $areas = array_map(static fn (int $cell): array => Outlines::area($cell, $photo), $cells);
    $left = min(array_column($areas, 'x'));
    $top = min(array_column($areas, 'y'));
    $right = max(array_map(static fn (array $area): int => $area['x'] + $area['width'], $areas));
    $bottom = max(array_map(static fn (array $area): int => $area['y'] + $area['height'], $areas));
    return ['x' => $left, 'y' => $top, 'width' => $right - $left, 'height' => $bottom - $top];
  }

  /**
   * Repaints a person, inside the cells they own.
   *
   * @param \GdImage $image
   *   The photo.
   * @param array{width: int, height: int} $photo
   *   The photo size.
   * @param list<int> $cells
   *   The cells the person owns.
   * @param \Drupal\genie_ledger\BodyPaint $paint
   *   The paint.
   */
  private function paintPerson(\GdImage $image, array $photo, array $cells, BodyPaint $paint): void {
    if ($cells === []) {
      return;
    }
    $bounds = self::bounds($cells, $photo);
    $original = $this->copyOf($image, $bounds);
    $fine = Outlines::FINE;
    $layer = $paint->pixelate ? $this->pixelated($original) : $this->copyOf($image, $bounds);
    $colors = array_map(
      static fn (string $color): int => imagecolorallocate($layer, ...sscanf($color, '#%02x%02x%02x')),
      $paint->palette,
    );
    foreach ($paint->spans as [$row, $first, $last, $cell]) {
      $top = $row * $fine - $bounds['y'];
      $left = max(0, $first * $fine - $bounds['x']);
      $right = min($bounds['width'], ($last + 1) * $fine - $bounds['x']);
      if ($cell === Sticker::EMPTY || $top < 0 || $top >= $bounds['height'] || $left >= $right) {
        continue;
      }
      if ($cell === Sticker::ORIGINAL) {
        imagecopy($layer, $original, $left, $top, $left, $top, $right - $left, $fine);
      }
      else {
        imagefilledrectangle($layer, $left, $top, $right - 1, $top + $fine - 1, $colors[hexdec($cell)]);
      }
    }
    foreach ($cells as $cell) {
      $area = Outlines::area($cell, $photo);
      imagecopy($image, $layer, $area['x'], $area['y'], $area['x'] - $bounds['x'], $area['y'] - $bounds['y'], $area['width'], $area['height']);
    }
  }

  /**
   * Turns a picture into pixel art on the fine grid, with a short palette.
   */
  private function pixelated(\GdImage $picture): \GdImage {
    $fine = Outlines::FINE;
    $width = imagesx($picture);
    $height = imagesy($picture);
    $columns = (int) ceil($width / $fine);
    $rows = (int) ceil($height / $fine);
    $small = imagescale($picture, $columns, $rows, IMG_BILINEAR_FIXED);
    imagetruecolortopalette($small, FALSE, self::PIXEL_ART_COLORS);
    $art = imagecreatetruecolor($width, $height);
    imagecopyresized($art, $small, 0, 0, 0, 0, $columns * $fine, $rows * $fine, $columns, $rows);
    return $art;
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
