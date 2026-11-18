<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\genie_ledger\Places;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\ClientGateway;

/**
 * Records the people and objects an assistant found in the photo.
 */
#[Tool(
  id: 'scan_photo',
  label: new TranslatableMarkup('Report who is in the photo'),
  description: new TranslatableMarkup('After looking at the photo with photo_get, reports every person and notable object in it, so nobody has to draw boxes. Give each person a short visible description such as "red shirt, Santa hat", a tight head box and a body box. Call it again to add someone who was missed or to replace a wrong box that nobody claimed.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'people' => [
        'type' => 'array',
        'items' => [
          'type' => 'object',
          'properties' => [
            'description' => ['type' => 'string'],
            'head' => Places::BOX_SCHEMA,
            'body' => Places::BOX_SCHEMA,
          ],
          'required' => ['description', 'head', 'body'],
        ],
      ],
      'objects' => [
        'type' => 'array',
        'items' => [
          'type' => 'object',
          'properties' => [
            'label' => ['type' => 'string'],
            'box' => Places::BOX_SCHEMA,
          ],
          'required' => ['label', 'box'],
        ],
      ],
    ],
    'required' => ['people', 'objects'],
  ],
  readOnly: FALSE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class ScanPhotoTool extends LedgerToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    try {
      return $this->desk->scan((int) $this->currentUser->id(), $arguments['people'] ?? NULL, $arguments['objects'] ?? NULL);
    }
    catch (\InvalidArgumentException $e) {
      throw new ToolCallException($e->getMessage(), previous: $e);
    }
  }

}
