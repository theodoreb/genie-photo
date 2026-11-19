<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\genie_ledger\Games;

/**
 * Hooks for the genie games.
 */
final class GenieHooks {

  public function __construct(private readonly Games $games) {}

  /**
   * Shows a player by the name they chose, without their game's id.
   */
  #[Hook('user_format_name_alter')]
  public function formatName(string &$name, AccountInterface $account): void {
    $name = Games::displayName($name);
  }

  /**
   * Abandons the games idle for a day.
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->games->abandonIdle();
  }

}
