<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\ClientGateway;

/**
 * Lets the uploader decide which objects must stay in the frame.
 */
#[Tool(
  id: 'protect_set',
  label: new TranslatableMarkup('Protect an object'),
  description: new TranslatableMarkup('For the uploader: keeps a marked object in the frame, or releases it.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'object' => ['type' => 'string', 'description' => 'The id of the object region.'],
      'protected' => ['type' => 'boolean'],
    ],
    'required' => ['object', 'protected'],
  ],
  readOnly: FALSE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class ProtectSetTool extends LedgerToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    if (!is_string($arguments['object'] ?? NULL) || !is_bool($arguments['protected'] ?? NULL)) {
      throw new ToolCallException('Name an object and say whether it is protected.');
    }
    try {
      return $this->desk->setProtected(
        (int) $this->currentUser->id(),
        $this->currentUser->getAccountName(),
        $arguments['object'],
        $arguments['protected'],
      );
    }
    catch (\InvalidArgumentException $e) {
      throw new ToolCallException($e->getMessage(), previous: $e);
    }
  }

}
