<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\ClientGateway;

/**
 * Lets the uploader ask the local model for boxes.
 */
#[Tool(
  id: 'propose_boxes',
  label: new TranslatableMarkup('Propose boxes'),
  description: new TranslatableMarkup('For the uploader: asks the vision model on this machine to propose boxes. People confirm them on the web view. The photo is never returned.'),
  readOnly: FALSE,
  destructive: FALSE,
  idempotent: FALSE,
  openWorld: FALSE,
)]
final class ProposeBoxesTool extends WebViewToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    try {
      return $this->desk->proposeBoxes((int) $this->currentUser->id());
    }
    catch (\InvalidArgumentException $e) {
      throw new ToolCallException($e->getMessage(), previous: $e);
    }
  }

}
