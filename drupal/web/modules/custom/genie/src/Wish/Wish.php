<?php

declare(strict_types=1);

namespace Drupal\genie\Wish;

/**
 * An understood wish.
 */
final readonly class Wish {

  /**
   * Builds the wish.
   *
   * @param \Drupal\genie\Wish\Operation $operation
   *   What the player asks for.
   * @param string $source
   *   What understood the wish: "model" or "keywords".
   */
  public function __construct(
    public Operation $operation,
    public string $source = 'keywords',
  ) {}

}
