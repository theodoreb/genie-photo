<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Describes the session, without any image data.
 */
#[Tool(
  id: 'session_get',
  label: new TranslatableMarkup('Session'),
  description: new TranslatableMarkup('Lists the revision, the photo size, the marked regions and the state of the frame.'),
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class SessionGetTool extends LedgerToolBase {

  /**
   * {@inheritdoc}
   */
  protected const STATES = ['start', 'open', 'closed', 'abandoned'];

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->desk->view((int) $this->currentUser->id());
  }

}
