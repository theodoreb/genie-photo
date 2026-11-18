<?php

declare(strict_types=1);

namespace Drupal\genie_ledger;

/**
 * The rendered picture of one revision.
 */
final readonly class Export {

  public function __construct(
    public string $png,
    public string $hash,
  ) {}

}
