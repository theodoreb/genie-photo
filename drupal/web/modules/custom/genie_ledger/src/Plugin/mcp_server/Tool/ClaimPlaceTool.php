<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\genie_ledger\Places;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\Exception\ToolCallException;
use Mcp\Server\ClientGateway;

/**
 * Gives the caller the place of one person in the photo.
 */
#[Tool(
  id: 'claim_place',
  label: new TranslatableMarkup('Claim my place'),
  description: new TranslatableMarkup('Tells the genie which person in the photo is you. Ask your person which one they are, then pass the id of that box from photo_get with a tight head box you see in the photo. To fix your head later, pass head alone. If your person is missing, pass head and body instead of an id.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'person' => ['type' => 'string', 'description' => 'The id of a proposed box.'],
      'head' => Places::BOX_SCHEMA,
      'body' => Places::BOX_SCHEMA,
    ],
  ],
  readOnly: FALSE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class ClaimPlaceTool extends LedgerToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    try {
      return $this->desk->claim(
        (int) $this->currentUser->id(),
        $this->currentUser->getAccountName(),
        $arguments['person'] ?? NULL,
        $arguments['head'] ?? NULL,
        $arguments['body'] ?? NULL,
      );
    }
    catch (\InvalidArgumentException $e) {
      throw new ToolCallException($e->getMessage(), previous: $e);
    }
  }

}
