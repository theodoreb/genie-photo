<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Plugin\mcp_server\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Drupal\simple_oauth\Authentication\TokenAuthUserInterface;
use Mcp\Exception\ToolCallException;
use Mcp\Server\ClientGateway;

/**
 * Records an approval the person gave on the web view.
 *
 * Only a token issued to the web view reaches it, so an assistant cannot
 * approve for its person without asking them. An authorization code goes to
 * the client's redirect address, and the web view alone listens on its own.
 */
#[Tool(
  id: 'export_approve',
  label: new TranslatableMarkup('Approve from the web view'),
  description: new TranslatableMarkup('Records the approval of the picture with this hash.'),
  inputSchema: [
    'type' => 'object',
    'properties' => ['hash' => ['type' => 'string']],
    'required' => ['hash'],
  ],
  readOnly: FALSE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class ExportApproveTool extends LedgerToolBase {

  private const WEB_CALLBACK = 'http://127.0.0.1:8000/oauth/callback';

  /**
   * {@inheritdoc}
   */
  public function checkAccess(AccountInterface $account): AccessResultInterface {
    $user = $account instanceof AccountProxyInterface ? $account->getAccount() : $account;
    $redirects = $user instanceof TokenAuthUserInterface
      ? array_column($user->getConsumer()->get('redirect')->getValue(), 'value')
      : [];
    $isWebView = $redirects === [Settings::get('genie_web_callback', self::WEB_CALLBACK)];
    return parent::checkAccess($account)->andIf(AccessResult::allowedIf($isWebView)->setCacheMaxAge(0));
  }

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    if (!is_string($arguments['hash'] ?? NULL)) {
      throw new ToolCallException('Give the hash of the picture you approve.');
    }
    try {
      return $this->desk->approve((int) $this->currentUser->id(), $arguments['hash']);
    }
    catch (\InvalidArgumentException $e) {
      throw new ToolCallException($e->getMessage(), previous: $e);
    }
  }

}
