<?php

declare(strict_types=1);

namespace Drupal\genie\Photo;

use Drupal\genie\Game\Session;
use Drupal\genie\Wish\Frame;
use Drupal\genie\Wish\Operation;

/**
 * Renders the shared picture: private areas, paint, covers, then the crop.
 */
final class Darkroom {

  private const COVER_COLOR = [43, 33, 64];

  /**
   * Room for the cell numbers around a grid view.
   */
  private const GRID_MARGIN = 40;

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

  /**
   * Gold at brightness 0, 128 and 255, mixed in a straight line between.
   */
  private const GOLD_RAMP = [[60, 35, 10], [200, 145, 35], [255, 240, 170]];

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
   * Applies covers and paint to the whole photo.
   */
  private function develop(string $jpeg, Session $session): \GdImage {
    $image = imagecreatefromstring($jpeg);
    $hidden = $this->hiddenHeads($session);

    // Hidden pixels go first, so no pixel art can carry them elsewhere.
    $this->paintOver($image, $hidden);
    $owners = Outlines::owners($session);
    $reach = Outlines::reach($session);
    foreach ($session->regions as $id => $region) {
      if ($region['kind'] === 'person' && isset($session->paints[$region['owner']])) {
        $this->paintPerson($image, $session->photo, array_keys($owners, $id, TRUE), $reach[$id], $session->paints[$region['owner']]);
      }
    }
    $this->dress($image, $session, $owners);
    // A cover wins over any paint or look on the same place.
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
   * Shows what a player can paint, as the picture has it, under the fine grid.
   *
   * A traced person gets a crop around their cells and safe zone. A person with
   * no outline gets the whole photo. Cells out of reach are dimmed.
   *
   * @return string
   *   JPEG bytes. The numbers name fine rows and columns of the whole photo.
   */
  public function bodyView(string $jpeg, Session $session, int $uid): string {
    $photo = $session->photo;
    $id = "person-$uid";
    $cells = Outlines::reach($session)[$id];
    $bounds = $session->regions[$id]['cells'] === [] ? ['x' => 0, 'y' => 0] + $photo : self::bounds($cells, $photo);
    $image = $this->develop($jpeg, $session);
    $dim = imagecolorallocatealpha($image, 0, 0, 0, 45);
    $reachable = array_flip($cells);
    $columns = Outlines::columns($photo);
    $cell = Outlines::CELL;
    for ($y = $bounds['y']; $y < $bounds['y'] + $bounds['height']; $y += $cell) {
      for ($x = $bounds['x']; $x < $bounds['x'] + $bounds['width']; $x += $cell) {
        if (!isset($reachable[intdiv($y, $cell) * $columns + intdiv($x, $cell)])) {
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
   * Repaints a person: pixel art on their cells, spans on their reach.
   *
   * @param \GdImage $image
   *   The photo.
   * @param array{width: int, height: int} $photo
   *   The photo size.
   * @param list<int> $own
   *   The cells the person owns.
   * @param list<int> $reach
   *   The cells the paint may change.
   * @param \Drupal\genie\Photo\BodyPaint $paint
   *   The paint.
   */
  private function paintPerson(\GdImage $image, array $photo, array $own, array $reach, BodyPaint $paint): void {
    if ($reach === []) {
      return;
    }
    $bounds = self::bounds($reach, $photo);
    $original = $this->copyOf($image, $bounds);
    $layer = $this->copyOf($image, $bounds);
    if ($paint->pixelate && $own !== []) {
      $ownBounds = self::bounds($own, $photo);
      $art = $this->pixelated($this->copyOf($image, $ownBounds));
      foreach ($own as $cell) {
        $area = Outlines::area($cell, $photo);
        imagecopy($layer, $art, $area['x'] - $bounds['x'], $area['y'] - $bounds['y'], $area['x'] - $ownBounds['x'], $area['y'] - $ownBounds['y'], $area['width'], $area['height']);
      }
    }
    $fine = Outlines::FINE;
    $colors = array_map(
      static fn (string $color): int => imagecolorallocate($layer, ...sscanf($color, '#%02x%02x%02x')),
      $paint->palette,
    );
    foreach ($paint->spans as [$row, $first, $last, $cell]) {
      $top = $row * $fine - $bounds['y'];
      $left = max(0, $first * $fine - $bounds['x']);
      $right = min($bounds['width'], ($last + 1) * $fine - $bounds['x']);
      if ($cell === BodyPaint::EMPTY || $top < 0 || $top >= $bounds['height'] || $left >= $right) {
        continue;
      }
      if ($cell === BodyPaint::ORIGINAL) {
        imagecopy($layer, $original, $left, $top, $left, $top, $right - $left, $fine);
      }
      else {
        imagefilledrectangle($layer, $left, $top, $right - 1, $top + $fine - 1, $colors[hexdec($cell)]);
      }
    }
    foreach ($reach as $cell) {
      $area = Outlines::area($cell, $photo);
      imagecopy($image, $layer, $area['x'], $area['y'], $area['x'] - $bounds['x'], $area['y'] - $bounds['y'], $area['width'], $area['height']);
    }
  }

  /**
   * Draws each player's look on their own person.
   *
   * @param \GdImage $image
   *   The photo.
   * @param \Drupal\genie\Game\Session $session
   *   The session.
   * @param array<int, string> $owners
   *   The region id that owns each cell.
   */
  private function dress(\GdImage $image, Session $session, array $owners): void {
    foreach ($session->regions as $id => $region) {
      if ($region['kind'] !== 'person' || !isset($session->wishes[$region['owner']])) {
        continue;
      }
      $look = Operation::from($session->wishes[$region['owner']]);
      if (!$look->isLook()) {
        continue;
      }
      $own = array_keys($owners, $id, TRUE);
      // A person hidden behind others owns no cell: the look keeps to the head.
      $areas = $own === [] ? [$region['head']] : array_map(
        static fn (int $cell): array => Outlines::area($cell, $session->photo),
        $own,
      );
      match ($look) {
        Operation::Gold => $this->gild($image, $areas),
      };
    }
  }

  /**
   * Maps the brightness of each pixel in the areas onto the gold ramp.
   *
   * @param \GdImage $image
   *   The photo.
   * @param list<array{x: int, y: int, width: int, height: int}> $areas
   *   The areas to gild.
   */
  private function gild(\GdImage $image, array $areas): void {
    $ramp = [];
    foreach (range(0, 255) as $level) {
      [$low, $high, $share] = $level < 128
        ? [self::GOLD_RAMP[0], self::GOLD_RAMP[1], $level / 128]
        : [self::GOLD_RAMP[1], self::GOLD_RAMP[2], ($level - 128) / 127];
      [$red, $green, $blue] = array_map(
        static fn (int $from, int $to): int => (int) round($from + ($to - $from) * $share),
        $low,
        $high,
      );
      $ramp[] = $red << 16 | $green << 8 | $blue;
    }
    foreach ($areas as $area) {
      for ($y = $area['y']; $y < $area['y'] + $area['height']; $y++) {
        for ($x = $area['x']; $x < $area['x'] + $area['width']; $x++) {
          $color = imagecolorat($image, $x, $y);
          $level = intdiv(299 * ($color >> 16 & 255) + 587 * ($color >> 8 & 255) + 114 * ($color & 255), 1000);
          imagesetpixel($image, $x, $y, $ramp[$level]);
        }
      }
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
   * Lists the heads of the players who asked to be covered or excluded.
   *
   * @return list<array{x: int, y: int, width: int, height: int}>
   *   The head boxes.
   */
  private function hiddenHeads(Session $session): array {
    $private = [Operation::Cover->value, Operation::Exclude->value];
    $hidden = [];
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

}
