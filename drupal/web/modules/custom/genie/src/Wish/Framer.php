<?php

declare(strict_types=1);

namespace Drupal\genie\Wish;

use Drupal\genie\Game\Session;

/**
 * Finds the frame as a pure function of the session.
 */
final class Framer {

  /**
   * Crop sizes tried, in twentieths of the photo: 100% down to 25%.
   */
  private const LARGEST = 20;
  private const SMALLEST = 5;
  private const SIZE_STEPS = 20;

  /**
   * Positions tried along each axis.
   */
  private const GRID = 24;

  /**
   * How far from the crop center a centered player may sit, as a crop share.
   */
  private const CENTER_TOLERANCE = 0.15;

  /**
   * Returns the largest valid crop, or the players who block every crop.
   */
  public function frame(Session $session): Frame|Collision {
    $constraints = $this->constraints($session);
    $frame = $this->search($session->photo, $constraints);
    if ($frame !== NULL) {
      return $frame;
    }

    $players = array_values(array_unique(array_column($constraints, 'player')));
    $removable = array_filter($players, fn (string $player): bool => $this->search(
      $session->photo,
      array_filter($constraints, static fn (array $constraint): bool => $constraint['player'] !== $player),
    ) !== NULL);
    return new Collision(array_values($removable ?: $players));
  }

  /**
   * Lists what each standing wish demands of a crop.
   *
   * @return list<array{player: string, accepts: callable(\Drupal\genie\Wish\Frame): bool}>
   *   The constraints.
   */
  private function constraints(Session $session): array {
    $constraints = [];
    foreach ($session->regions as $id => $region) {
      if ($region['kind'] === 'object') {
        if (isset($session->protected[$id])) {
          $constraints[] = [
            'player' => $session->protected[$id],
            'accepts' => static fn (Frame $frame): bool => self::contains($frame, $region),
          ];
        }
        continue;
      }
      if (($session->wishes[$region['owner']] ?? NULL) === Operation::Exclude->value) {
        $constraints[] = [
          'player' => $region['label'],
          'accepts' => static fn (Frame $frame): bool => !self::touches($frame, $region),
        ];
      }
      if (isset($session->centered[$region['owner']])) {
        $constraints[] = [
          'player' => $region['label'],
          'accepts' => static fn (Frame $frame): bool => self::isCentered($frame, $region['head']),
        ];
      }
    }
    return $constraints;
  }

  /**
   * Walks the crops from largest to smallest, top to bottom, left to right.
   *
   * @param array{width: int, height: int} $photo
   *   The photo size.
   * @param array<array{player: string, accepts: callable(\Drupal\genie\Wish\Frame): bool}> $constraints
   *   What the crop must satisfy.
   */
  private function search(array $photo, array $constraints): ?Frame {
    for ($size = self::LARGEST; $size >= self::SMALLEST; $size--) {
      $width = (int) round($photo['width'] * $size / self::SIZE_STEPS);
      $height = (int) round($photo['height'] * $size / self::SIZE_STEPS);
      for ($row = 0; $row <= self::GRID; $row++) {
        for ($column = 0; $column <= self::GRID; $column++) {
          $frame = new Frame(
            (int) round(($photo['width'] - $width) * $column / self::GRID),
            (int) round(($photo['height'] - $height) * $row / self::GRID),
            $width,
            $height,
          );
          foreach ($constraints as $constraint) {
            if (!$constraint['accepts']($frame)) {
              continue 2;
            }
          }
          return $frame;
        }
      }
    }
    return NULL;
  }

  /**
   * Tells whether a crop overlaps a region.
   *
   * @param \Drupal\genie\Wish\Frame $frame
   *   The crop.
   * @param array{x: int, y: int, width: int, height: int} $region
   *   The region.
   */
  private static function touches(Frame $frame, array $region): bool {
    return $frame->x < $region['x'] + $region['width'] && $region['x'] < $frame->x + $frame->width
      && $frame->y < $region['y'] + $region['height'] && $region['y'] < $frame->y + $frame->height;
  }

  /**
   * Tells whether a crop holds a whole region.
   *
   * @param \Drupal\genie\Wish\Frame $frame
   *   The crop.
   * @param array{x: int, y: int, width: int, height: int} $region
   *   The region.
   */
  private static function contains(Frame $frame, array $region): bool {
    return $region['x'] >= $frame->x && $region['y'] >= $frame->y
      && $region['x'] + $region['width'] <= $frame->x + $frame->width
      && $region['y'] + $region['height'] <= $frame->y + $frame->height;
  }

  /**
   * Tells whether a region's center lies near the crop's center.
   *
   * @param \Drupal\genie\Wish\Frame $frame
   *   The crop.
   * @param array{x: int, y: int, width: int, height: int} $region
   *   The region.
   */
  private static function isCentered(Frame $frame, array $region): bool {
    $offsetX = abs($region['x'] + $region['width'] / 2 - ($frame->x + $frame->width / 2));
    $offsetY = abs($region['y'] + $region['height'] / 2 - ($frame->y + $frame->height / 2));
    return $offsetX <= $frame->width * self::CENTER_TOLERANCE && $offsetY <= $frame->height * self::CENTER_TOLERANCE;
  }

}
