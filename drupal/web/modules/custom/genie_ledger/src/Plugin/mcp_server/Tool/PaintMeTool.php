<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\genie_ledger\BodyPaint;
use Drupal\genie_ledger\Sticker;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\ClientGateway;

/**
 * Paints the caller's whole person.
 */
#[Tool(
  id: 'paint_me',
  label: new TranslatableMarkup('Paint my whole person'),
  description: new TranslatableMarkup('Repaints your whole person in the shared photo. Call photo_get with view my_body first. Its "editable" rectangles and bright image area are the only cells you can change: your person and a one-block safe zone of background, or the whole photo around other people when you have no outline. "on_person" lists the cells on your person. Set pixelate to true to turn your person into pixel art. Add spans to paint on top: each span is [row, first column, last column, cell] on the numbered fine grid. A cell is a hex digit that picks a palette color, "*" for the photo pixels or "." to leave it. A new paint replaces the old one, and pixelate false with no spans removes it. The result shows what you can paint, so you can check it and correct it.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'pixelate' => ['type' => 'boolean'],
      'spans' => [
        'type' => 'array',
        'description' => 'Runs of fine cells as [row, first column, last column, cell], such as [120, 40, 64, "0"].',
        'items' => ['type' => 'array', 'minItems' => 4, 'maxItems' => 4],
      ],
      'palette' => [
        'type' => 'array',
        'items' => ['type' => 'string', 'description' => 'A color such as #ff8800.'],
        'maxItems' => Sticker::MAX_COLORS,
      ],
    ],
  ],
  readOnly: FALSE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class PaintMeTool extends LedgerToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    try {
      $paint = BodyPaint::fromInput($arguments['pixelate'] ?? NULL, $arguments['palette'] ?? NULL, $arguments['spans'] ?? NULL);
    }
    catch (\InvalidArgumentException $e) {
      throw new ToolCallException($e->getMessage(), previous: $e);
    }
    $uid = (int) $this->currentUser->id();
    $outcome = $this->desk->paint($uid, $this->currentUser->getAccountName(), $paint);
    if (!$outcome['granted']) {
      return $outcome;
    }
    return $this->imageResult(['info' => $outcome] + $this->desk->image($uid, 'my_body'));
  }

}
