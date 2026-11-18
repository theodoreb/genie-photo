<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\ClientGateway;

/**
 * Passes a player's wish to the genie.
 */
#[Tool(
  id: 'make_wish',
  label: new TranslatableMarkup('Make a wish'),
  description: new TranslatableMarkup('Tells the genie, in plain words, how you want to appear in the shared photo. A new wish replaces your earlier one.'),
  inputSchema: [
    'type' => 'object',
    'properties' => ['wish' => ['type' => 'string', 'description' => 'For example: hide my face.']],
    'required' => ['wish'],
  ],
  readOnly: FALSE,
  destructive: FALSE,
  idempotent: FALSE,
  openWorld: FALSE,
)]
final class MakeWishTool extends LedgerToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    if (!is_string($arguments['wish'] ?? NULL)) {
      throw new ToolCallException('A wish is a text.');
    }
    return $this->desk->grant((int) $this->currentUser->id(), $this->currentUser->getAccountName(), $arguments['wish']);
  }

}
