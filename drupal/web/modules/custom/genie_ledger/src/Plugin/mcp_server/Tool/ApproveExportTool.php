<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Exception\ClientException;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Elicitation\BooleanSchemaDefinition;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Server\ClientGateway;

/**
 * Asks the human behind an assistant to approve the current picture.
 */
#[Tool(
  id: 'approve_export',
  label: new TranslatableMarkup('Approve the photo'),
  description: new TranslatableMarkup('Asks you, the person, to approve the shared photo exactly as it is now. If your assistant cannot ask, you approve on the web view.'),
  readOnly: FALSE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class ApproveExportTool extends LedgerToolBase {

  private const ON_THE_WEB = 'Open the web view and approve the photo there.';

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    $hash = $this->desk->view((int) $this->currentUser->id())['export']['hash'] ?? NULL;
    if ($hash === NULL) {
      throw new ToolCallException('There is no photo to approve right now.');
    }
    if (!$gateway->supportsElicitation()) {
      return ['approved' => FALSE, 'reply' => self::ON_THE_WEB];
    }

    try {
      $answer = $gateway->elicit(
        'Do you approve the shared photo exactly as it looks now? Any later change cancels this approval.',
        new ElicitationSchema(['approve' => new BooleanSchemaDefinition('I approve this photo')], ['approve']),
      );
    }
    catch (ClientException) {
      return ['approved' => FALSE, 'reply' => self::ON_THE_WEB];
    }
    if (!$answer->isAccepted() || ($answer->content['approve'] ?? FALSE) !== TRUE) {
      return ['approved' => FALSE, 'reply' => 'Nothing was approved.'];
    }
    return ['approved' => TRUE] + $this->approve($hash);
  }

  /**
   * Records the approval.
   *
   * @return array<string, mixed>
   *   The state of the export.
   */
  private function approve(string $hash): array {
    try {
      return $this->desk->approve((int) $this->currentUser->id(), $hash);
    }
    catch (\InvalidArgumentException $e) {
      throw new ToolCallException($e->getMessage(), previous: $e);
    }
  }

}
