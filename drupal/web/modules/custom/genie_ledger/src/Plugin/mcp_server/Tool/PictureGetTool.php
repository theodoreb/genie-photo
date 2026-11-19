<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\ClientGateway;

/**
 * Gives the web view the images it shows.
 */
#[Tool(
  id: 'picture_get',
  label: new TranslatableMarkup('Get an image for the web view'),
  description: new TranslatableMarkup('Returns the original photo, or the shared picture of the given revision.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'view' => ['type' => 'string', 'enum' => ['photo', 'frame']],
      'revision' => ['type' => 'integer'],
    ],
    'required' => ['view'],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class PictureGetTool extends WebViewToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    try {
      return $this->imageResult($this->desk->webPicture((string) ($arguments['view'] ?? ''), $arguments['revision'] ?? NULL));
    }
    catch (\InvalidArgumentException $e) {
      throw new ToolCallException($e->getMessage(), previous: $e);
    }
  }

}
