<?php

declare(strict_types=1);

namespace Drupal\genie\Plugin\mcp_server\Tool\Visitor;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\genie\Plugin\mcp_server\Tool\VisitorToolBase;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Describes one game to anyone.
 */
#[Tool(
  id: 'web_game_view',
  label: new TranslatableMarkup('Look at a game'),
  description: new TranslatableMarkup('Returns the state of a game, its players, the people left to claim and where its photo comes from.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'game' => ['type' => 'string', 'description' => 'The UUID of the game.'],
    ],
    'required' => ['game'],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class GameViewTool extends VisitorToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    $this->enter($arguments['game'] ?? NULL);
    return $this->gameView->publicView();
  }

}
