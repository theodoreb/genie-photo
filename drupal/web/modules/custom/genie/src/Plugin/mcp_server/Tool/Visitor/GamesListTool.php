<?php

declare(strict_types=1);

namespace Drupal\genie\Plugin\mcp_server\Tool\Visitor;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\genie\Plugin\mcp_server\Tool\VisitorToolBase;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Lists the games anyone can look at.
 */
#[Tool(
  id: 'web_games_list',
  label: new TranslatableMarkup('List the games'),
  description: new TranslatableMarkup('Lists the open games, then the latest finished ones, each with its players, the people left to claim and its join address.'),
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class GamesListTool extends VisitorToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    $games = [];
    foreach ($this->games->listed() as $game) {
      $this->game->enter($game);
      $games[] = $this->gameView->publicView();
    }
    return ['games' => $games];
  }

}
