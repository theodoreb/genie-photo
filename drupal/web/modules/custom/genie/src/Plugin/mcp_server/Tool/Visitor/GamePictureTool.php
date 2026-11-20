<?php

declare(strict_types=1);

namespace Drupal\genie\Plugin\mcp_server\Tool\Visitor;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\genie\Game\Ledger;
use Drupal\genie\Photo\Pictures;
use Drupal\genie\Plugin\mcp_server\Tool\ImageResultTrait;
use Drupal\genie\Plugin\mcp_server\Tool\VisitorToolBase;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;
use Mcp\Server\Exception\ToolCallException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Gives the public pages the images of a game.
 */
#[Tool(
  id: 'web_game_picture',
  label: new TranslatableMarkup('Get an image of a game'),
  description: new TranslatableMarkup('Returns the original photo of a game, or its shared picture as it is now.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'game' => ['type' => 'string', 'description' => 'The UUID of the game.'],
      'view' => ['type' => 'string', 'enum' => ['photo', 'frame']],
    ],
    'required' => ['game', 'view'],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class GamePictureTool extends VisitorToolBase {

  use ImageResultTrait;

  /**
   * Renders the images.
   */
  private Pictures $pictures;

  /**
   * Reads the revision the shared picture is at.
   */
  private Ledger $ledger;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $tool = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $tool->pictures = $container->get(Pictures::class);
    $tool->ledger = $container->get(Ledger::class);
    return $tool;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    $this->enter($arguments['game'] ?? NULL);
    try {
      return $this->imageResult($this->pictures->webPicture((string) ($arguments['view'] ?? ''), $this->ledger->counters()['revision']));
    }
    catch (\InvalidArgumentException $e) {
      throw new ToolCallException($e->getMessage(), previous: $e);
    }
  }

}
