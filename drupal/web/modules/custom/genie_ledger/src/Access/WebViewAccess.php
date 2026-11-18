<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\simple_oauth\Authentication\TokenAuthUserInterface;

/**
 * Allows only tokens issued to the web view's OAuth client.
 *
 * The client is confidential, and `drush genie:web-client` hands its secret to
 * the web view alone. A redirect address proves nothing here: the OAuth server
 * ignores the port on loopback addresses.
 */
final class WebViewAccess {

  public const CLIENT_ID = 'genie-web';

  /**
   * Checks that the account's token belongs to the web view.
   */
  public function access(AccountInterface $account): AccessResultInterface {
    $user = $account instanceof AccountProxyInterface ? $account->getAccount() : $account;
    return AccessResult::allowedIf(
      $user instanceof TokenAuthUserInterface && $user->getConsumer()->getClientId() === self::CLIENT_ID,
    )->setCacheMaxAge(0);
  }

}
