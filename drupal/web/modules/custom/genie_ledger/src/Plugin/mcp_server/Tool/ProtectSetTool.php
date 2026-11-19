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
 * Lets the uploader decide which objects must stay in the frame.
 */
#[Tool(
  id: 'protect_set',
  label: new TranslatableMarkup('Protect an object'),
  description: new TranslatableMarkup('For the uploader: keeps a marked object in the frame, or releases it.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'object' => ['type' => 'string', 'description' => 'The id of the object region.'],
      'protected' => ['type' => 'boolean'],
    ],
    'required' => ['object', 'protected'],
  ],
  readOnly: FALSE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class ProtectSetTool extends WebViewToolBase {

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
    if (!is_string($arguments['object'] ?? NULL) || !is_bool($arguments['protected'] ?? NULL)) {
      throw new ToolCallException('Name an object and say whether it is protected.');
    }
    try {
      return $this->wishes->setProtected(
        (int) $this->currentUser->id(),
        $this->currentUser->getDisplayName(),
        $arguments['object'],
        $arguments['protected'],
      );
    }
    catch (\InvalidArgumentException $e) {
      throw new ToolCallException($e->getMessage(), previous: $e);
    }
  }

}
