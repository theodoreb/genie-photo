<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Plugin\mcp_server\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\mcp_server\Plugin\ToolPluginBase;

/**
 * Base for ledger tools: only signed-in players see or call them.
 */
abstract class LedgerToolBase extends ToolPluginBase {

  /**
   * {@inheritdoc}
   */
  protected function defaultConfiguration(): array {
    return ['enabled' => TRUE];
  }

  /**
   * {@inheritdoc}
   */
  public function checkAccess(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIf($account->isAuthenticated() && $account->hasPermission('play genie'))
      ->cachePerPermissions();
  }

}
