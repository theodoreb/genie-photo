<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\Exception\ToolCallException;
use Mcp\Server\ClientGateway;

/**
 * Shows the assistant the photo, the shared picture or what it can paint.
 */
#[Tool(
  id: 'photo_get',
  label: new TranslatableMarkup('See the photo'),
  description: new TranslatableMarkup('Returns an image and a JSON description. view "photo" is the original group photo, with every known box in its pixels: use it to find people and to learn who is who. view "picture" is the shared picture as every wish and paint leaves it. view "outline" is the photo under a numbered grid with the boxes of the local person detector, for tracing people before scan_photo. view "my_body" shows what you can paint under the fine grid paint_me uses: your person and a safe zone, or the whole photo when you have no outline. "editable" lists the cells you can paint.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'view' => ['type' => 'string', 'enum' => ['photo', 'picture', 'outline', 'my_body']],
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
  protected const STATES = ['open', 'closed', 'abandoned'];

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
