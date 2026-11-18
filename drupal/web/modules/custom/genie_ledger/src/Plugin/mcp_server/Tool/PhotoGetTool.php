<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\ClientGateway;

/**
 * Shows the assistant the photo, the shared picture or its own head.
 */
#[Tool(
  id: 'photo_get',
  label: new TranslatableMarkup('See the photo'),
  description: new TranslatableMarkup('Returns an image and a JSON description. view "photo" is the original group photo, with every known box in its pixels: use it to find people and to learn who is who. view "picture" is the shared picture as every wish and sticker leaves it. view "outline" is the photo under a numbered grid with the boxes of the local person detector, for tracing people before scan_photo. view "my_head" is your person\'s head under the numbered 32 by 32 sticker grid, which shows exactly where each sticker cell lands. view "my_body" is your whole person under the fine grid paint_me uses, with the cells you can paint listed in "editable".'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'view' => ['type' => 'string', 'enum' => ['photo', 'picture', 'outline', 'my_head', 'my_body']],
    ],
    'required' => ['view'],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class PhotoGetTool extends LedgerToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    try {
      return $this->imageResult($this->desk->image((int) $this->currentUser->id(), (string) ($arguments['view'] ?? '')));
    }
    catch (\InvalidArgumentException $e) {
      throw new ToolCallException($e->getMessage(), previous: $e);
    }
  }

}
