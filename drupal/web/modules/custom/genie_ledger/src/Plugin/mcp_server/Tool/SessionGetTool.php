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
  description: new TranslatableMarkup('Lists the revision, the photo size and the marked regions.'),
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class SessionGetTool extends LedgerToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    $session = $this->ledger->read();
    return [
      'revision' => $session->revision,
      'photo' => $session->photo,
      'is_uploader' => $session->uploader === (int) $this->currentUser->id(),
      'regions' => array_values($session->regions),
    ];
  }

}
