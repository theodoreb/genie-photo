<?php

declare(strict_types=1);

namespace Drupal\genie\Plugin\mcp_server\Tool;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\genie\Web\WebViewAccess;

/**
 * Base for tools people use on the web view, which assistants do not get.
 */
abstract class WebViewToolBase extends LedgerToolBase {

  /**
   * {@inheritdoc}
   */
  public function checkAccess(AccountInterface $account): AccessResultInterface {
    return parent::checkAccess($account)->andIf((new WebViewAccess())->access($account));
  }

}
