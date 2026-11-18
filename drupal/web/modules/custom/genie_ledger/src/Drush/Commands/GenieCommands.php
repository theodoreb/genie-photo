<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Drush\Commands;

use Drupal\genie_ledger\Ledger;
use Drupal\genie_ledger\PhotoStore;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Commands for the genie session.
 */
final class GenieCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly Ledger $ledger,
    private readonly PhotoStore $photos,
  ) {
    parent::__construct();
  }

  /**
   * Starts over with no photo and no regions.
   */
  #[CLI\Command(name: 'genie:reset')]
  public function reset(): void {
    $this->ledger->reset();
    $this->photos->delete();
    $this->logger()->success('The genie session is empty.');
  }

}
