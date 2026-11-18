<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\ClientGateway;

/**
 * Waits until the session moves past a revision.
 */
#[Tool(
  id: 'wait_for_update',
  label: new TranslatableMarkup('Wait for a change'),
  description: new TranslatableMarkup('Waits up to 25 seconds for the session to move past the revision you know. Call it again if nothing changed.'),
  inputSchema: [
    'type' => 'object',
    'properties' => ['after_revision' => ['type' => 'integer']],
    'required' => ['after_revision'],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class WaitForUpdateTool extends LedgerToolBase {

  private const MAX_SECONDS = 25;
  private const POLL_MICROSECONDS = 500_000;

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    if (!is_int($arguments['after_revision'] ?? NULL)) {
      throw new ToolCallException('Give the revision you know as an integer.');
    }
    $deadline = microtime(TRUE) + self::MAX_SECONDS;
    do {
      $revision = $this->ledger->read()->revision;
      if ($revision > $arguments['after_revision']) {
        return ['changed' => TRUE, 'revision' => $revision];
      }
      usleep(self::POLL_MICROSECONDS);
    } while (microtime(TRUE) < $deadline);
    return ['changed' => FALSE, 'revision' => $revision];
  }

}
