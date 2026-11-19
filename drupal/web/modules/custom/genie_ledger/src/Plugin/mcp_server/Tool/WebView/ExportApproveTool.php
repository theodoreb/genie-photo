<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Plugin\mcp_server\Tool\WebView;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\genie_ledger\Game\Approvals;
use Drupal\genie_ledger\Plugin\mcp_server\Tool\WebViewToolBase;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;
use Mcp\Server\Exception\ToolCallException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Records an approval the person gave on the web view.
 *
 * Only a token issued to the web view reaches it, so an assistant cannot
 * approve for its person without asking them.
 */
#[Tool(
  id: 'web_export_approve',
  label: new TranslatableMarkup('Approve from the web view'),
  description: new TranslatableMarkup('Records the approval of the picture with this hash.'),
  inputSchema: [
    'type' => 'object',
    'properties' => ['hash' => ['type' => 'string']],
    'required' => ['hash'],
  ],
  outputSchema: Approvals::RECEIPT_SCHEMA,
  readOnly: FALSE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class ExportApproveTool extends WebViewToolBase {

  /**
   * Records the approvals.
   */
  private Approvals $approvals;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $tool = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $tool->approvals = $container->get(Approvals::class);
    return $tool;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    if (!is_string($arguments['hash'] ?? NULL)) {
      throw new ToolCallException('Give the hash of the picture you approve.');
    }
    try {
      return $this->approvals->approve((int) $this->currentUser->id(), $arguments['hash']);
    }
    catch (\InvalidArgumentException $e) {
      throw new ToolCallException($e->getMessage(), previous: $e);
    }
  }

}
