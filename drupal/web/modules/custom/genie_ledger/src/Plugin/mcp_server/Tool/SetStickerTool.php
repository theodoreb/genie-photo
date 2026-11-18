<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\genie_ledger\Sticker;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\ClientGateway;

/**
 * Places a pixel-art sticker on the caller's own region.
 */
#[Tool(
  id: 'set_sticker',
  label: new TranslatableMarkup('Set my sticker'),
  description: new TranslatableMarkup('Draws a 32 by 32 pixel-art sticker on your head in the shared photo. It is square, as wide as your place and starts at the top of it. You never see the photo, so you draw blind. Send 32 rows of 32 characters. Short rows and missing rows stay empty, and extra cells are cut. "." leaves the photo untouched, "*" keeps the photo pixels, and a hex digit picks a palette color. Blur from 0 to 8 applies to every non-empty cell. A new sticker replaces the earlier one.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'rows' => [
        'type' => 'array',
        'items' => ['type' => 'string'],
      ],
      'palette' => [
        'type' => 'array',
        'items' => ['type' => 'string', 'description' => 'A color such as #ff8800.'],
        'maxItems' => Sticker::MAX_COLORS,
      ],
      'blur' => ['type' => 'integer', 'minimum' => 0, 'maximum' => Sticker::MAX_BLUR],
    ],
    'required' => ['rows', 'palette', 'blur'],
  ],
  readOnly: FALSE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class SetStickerTool extends LedgerToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    try {
      $sticker = Sticker::fromInput($arguments['rows'] ?? NULL, $arguments['palette'] ?? NULL, $arguments['blur'] ?? NULL);
    }
    catch (\InvalidArgumentException $e) {
      throw new ToolCallException($e->getMessage(), previous: $e);
    }
    return $this->desk->setSticker((int) $this->currentUser->id(), $this->currentUser->getAccountName(), $sticker);
  }

}
