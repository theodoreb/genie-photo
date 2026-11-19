<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Wish;

/**
 * An understood wish.
 */
final readonly class Wish {

  /**
   * Builds the wish.
   *
   * @param \Drupal\genie_ledger\Wish\Operation $operation
   *   What the player asks for.
   * @param string|null $object
   *   The region id of the object a protect wish names.
   * @param string $source
   *   What understood the wish: "model" or "keywords".
   */
  public function __construct(
    public Operation $operation,
    public ?string $object = NULL,
    public string $source = 'keywords',
  ) {}

}
