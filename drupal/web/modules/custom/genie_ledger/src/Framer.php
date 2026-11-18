<?php

declare(strict_types=1);

namespace Drupal\genie_ledger;

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
   * Returns the largest valid crop, preferring the top, then the left.
   */
  public function frame(Session $session): Frame|Collision {
    $photo = $session->photo;
    $excluded = $this->excluded($session);

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
          if ($this->isClear($frame, $excluded)) {
            return $frame;
          }
        }
      }
    }
    return new Collision(array_column($excluded, 'label'));
  }

  /**
   * Lists the regions of players who asked to be left out.
   *
   * @return list<array{kind: string, owner: int, label: string, x: int, y: int, width: int, height: int}>
   *   The regions.
   */
  private function excluded(Session $session): array {
    return array_values(array_filter(
      $session->regions,
      static fn (array $region): bool => $region['kind'] === 'person'
        && ($session->wishes[$region['owner']] ?? NULL) === Operation::Exclude->value,
    ));
  }

  /**
   * Tells whether a crop touches none of the regions.
   *
   * @param \Drupal\genie_ledger\Frame $frame
   *   The crop.
   * @param list<array{x: int, y: int, width: int, height: int}> $regions
   *   The regions to avoid.
   */
  private function isClear(Frame $frame, array $regions): bool {
    foreach ($regions as $region) {
      if ($frame->x < $region['x'] + $region['width'] && $region['x'] < $frame->x + $frame->width
        && $frame->y < $region['y'] + $region['height'] && $region['y'] < $frame->y + $frame->height) {
        return FALSE;
      }
    }
    return TRUE;
  }

}
