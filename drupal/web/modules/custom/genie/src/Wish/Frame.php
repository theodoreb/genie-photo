<?php

declare(strict_types=1);

namespace Drupal\genie\Wish;

/**
 * The crop of the photo that satisfies every standing wish.
 */
final readonly class Frame {

  public function __construct(
    public int $x,
    public int $y,
    public int $width,
    public int $height,
  ) {}

}
