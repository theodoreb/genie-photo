<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\genie_ledger\Wishes;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;
use Mcp\Server\Exception\ToolCallException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Passes a player's wish to the genie.
 */
#[Tool(
  id: 'make_wish',
  label: new TranslatableMarkup('Make a wish'),
  description: new TranslatableMarkup('Tells the genie, in plain words, how you want to appear in the shared photo. A new wish replaces your earlier one.'),
  inputSchema: [
    'type' => 'object',
    'properties' => ['wish' => ['type' => 'string', 'description' => 'For example: hide my face.']],
    'required' => ['wish'],
  ],
  readOnly: FALSE,
  destructive: FALSE,
  idempotent: FALSE,
  openWorld: FALSE,
)]
final class MakeWishTool extends LedgerToolBase {

  private const MAX_LENGTH = 300;

  /**
   * Grants the player's wishes.
   */
  private Wishes $wishes;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $tool = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $tool->wishes = $container->get(Wishes::class);
    return $tool;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    if (!is_string($arguments['wish'] ?? NULL)) {
      throw new ToolCallException('A wish is a text.');
    }
    if (mb_strlen($arguments['wish']) > self::MAX_LENGTH) {
      throw new ToolCallException(sprintf('Keep a wish under %d characters.', self::MAX_LENGTH));
    }
    return $this->wishes->grant((int) $this->currentUser->id(), $this->currentUser->getDisplayName(), $arguments['wish']);
  }

}
