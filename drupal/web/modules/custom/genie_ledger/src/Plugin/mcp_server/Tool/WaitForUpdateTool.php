<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\Exception\ToolCallException;
use Mcp\Server\ClientGateway;

/**
 * Waits until the session changes.
 */
#[Tool(
  id: 'wait_for_update',
  label: new TranslatableMarkup('Wait for a change'),
  description: new TranslatableMarkup('Waits up to 25 seconds for the session to move past the update number session_get or approve_export gave you. Approvals count. Call it again if nothing changed.'),
  inputSchema: [
    'type' => 'object',
    'properties' => ['after_update' => ['type' => 'integer']],
    'required' => ['after_update'],
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
    if (!is_int($arguments['after_update'] ?? NULL)) {
      throw new ToolCallException('Give the update number you know as an integer.');
    }
    $deadline = microtime(TRUE) + self::MAX_SECONDS;
    do {
      $counters = $this->ledger->counters();
      if ($counters['update'] > $arguments['after_update']) {
        return ['changed' => TRUE] + $counters;
      }
      usleep(self::POLL_MICROSECONDS);
    } while (microtime(TRUE) < $deadline);
    return ['changed' => FALSE] + $counters;
  }

}
