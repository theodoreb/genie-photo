<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Tells a player who they are signed in as.
 */
#[Tool(
  id: 'my_status',
  label: new TranslatableMarkup('My status'),
  description: new TranslatableMarkup('Says which player you are signed in as.'),
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class MyStatusTool extends LedgerToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return [
      'uid' => (int) $this->currentUser->id(),
      'name' => $this->currentUser->getAccountName(),
    ];
  }

}
