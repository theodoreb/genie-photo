<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\genie_ledger\People;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;
use Mcp\Server\Exception\ToolCallException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets the uploader remove a wrong proposal.
 */
#[Tool(
  id: 'proposal_dismiss',
  label: new TranslatableMarkup('Dismiss a proposal'),
  description: new TranslatableMarkup('For the uploader: removes a proposed box that is wrong.'),
  inputSchema: [
    'type' => 'object',
    'properties' => ['proposal' => ['type' => 'string']],
    'required' => ['proposal'],
  ],
  readOnly: FALSE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class ProposalDismissTool extends WebViewToolBase {

  /**
   * Records who is in the photo.
   */
  private People $people;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $tool = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $tool->people = $container->get(People::class);
    return $tool;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    if (!is_string($arguments['proposal'] ?? NULL)) {
      throw new ToolCallException('Name the proposal.');
    }
    try {
      return $this->people->dismissProposal((int) $this->currentUser->id(), $arguments['proposal']);
    }
    catch (\InvalidArgumentException $e) {
      throw new ToolCallException($e->getMessage(), previous: $e);
    }
  }

}
