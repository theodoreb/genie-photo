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
  description: new TranslatableMarkup('Paints a 32 by 32 pixel-art sticker over your person\'s head in the shared photo. Call photo_get with view my_head first: it shows the head under the same numbered grid, so you can put pixels exactly where they belong, on a mask or a mouth for example. Draw with spans: each span is [row, first column, last column, cell], and unlisted cells stay empty. A cell is a hex digit that picks a palette color, "*" for the photo pixels or "." for empty. You can send rows of 32 characters instead. Blur from 0 to 8 applies to every non-empty cell. A new sticker replaces the old one. The result shows the head with your sticker, so you can check it and correct it.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'spans' => [
        'type' => 'array',
        'description' => 'Runs of cells as [row, first column, last column, cell], such as [20, 8, 23, "0"].',
        'items' => ['type' => 'array', 'minItems' => 4, 'maxItems' => 4],
      ],
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
    'required' => ['palette', 'blur'],
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
      $sticker = Sticker::fromInput($arguments['rows'] ?? NULL, $arguments['palette'] ?? NULL, $arguments['blur'] ?? NULL, $arguments['spans'] ?? NULL);
    }
    catch (\InvalidArgumentException $e) {
      throw new ToolCallException($e->getMessage(), previous: $e);
    }
    $outcome = $this->desk->setSticker((int) $this->currentUser->id(), $this->currentUser->getAccountName(), $sticker);
    if (!$outcome['granted']) {
      return $outcome;
    }
    $image = $this->desk->image((int) $this->currentUser->id(), 'my_head');
    return $this->imageResult(['info' => $outcome] + $image);
  }

}
