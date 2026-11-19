<?php

declare(strict_types=1);

namespace Drupal\genie\Wish;

/**
 * Standing wishes that no crop can satisfy together.
 */
final readonly class Collision {

  /**
   * Builds the collision.
   *
   * @param list<string> $players
   *   Names of the players involved.
   */
  public function __construct(public array $players) {}

}
