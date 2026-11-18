<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\ClientGateway;

/**
 * Marks the caller's own region, or an object when the caller is the uploader.
 */
#[Tool(
  id: 'region_put',
  label: new TranslatableMarkup('Mark a region'),
  description: new TranslatableMarkup('Marks your own place in the photo. The uploader can also mark a named object.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'kind' => ['type' => 'string', 'enum' => ['person', 'object']],
      'label' => ['type' => 'string', 'description' => 'Name of the object.'],
      'x' => ['type' => 'integer'],
      'y' => ['type' => 'integer'],
      'width' => ['type' => 'integer'],
      'height' => ['type' => 'integer'],
    ],
    'required' => ['kind', 'x', 'y', 'width', 'height'],
  ],
  readOnly: FALSE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class RegionPutTool extends WebViewToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    try {
      return $this->desk->putRegion((int) $this->currentUser->id(), $this->currentUser->getAccountName(), $arguments);
    }
    catch (\InvalidArgumentException $e) {
      throw new ToolCallException($e->getMessage(), previous: $e);
    }
  }

}
