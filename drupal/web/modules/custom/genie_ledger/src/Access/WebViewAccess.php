<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Drupal\simple_oauth\Authentication\TokenAuthUserInterface;

/**
 * Allows only tokens issued to the web view.
 *
 * An authorization code goes to the client's redirect address, and the web view
 * alone listens on its own. Assistants therefore never reach the pixels or the
 * web view's approval tool over MCP or HTTP.
 */
final class WebViewAccess {

  private const CALLBACK = 'http://127.0.0.1:8000/oauth/callback';

  /**
   * Checks that the account's token belongs to the web view.
   */
  public function access(AccountInterface $account): AccessResultInterface {
    $user = $account instanceof AccountProxyInterface ? $account->getAccount() : $account;
    $redirects = $user instanceof TokenAuthUserInterface
      ? array_column($user->getConsumer()->get('redirect')->getValue(), 'value')
      : [];
    return AccessResult::allowedIf($redirects === [Settings::get('genie_web_callback', self::CALLBACK)])
      ->setCacheMaxAge(0);
  }

}
