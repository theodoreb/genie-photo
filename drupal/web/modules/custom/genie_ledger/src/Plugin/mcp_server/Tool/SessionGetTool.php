<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\genie_ledger\GameView;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Describes the session, without any image data.
 */
#[Tool(
  id: 'session_get',
  label: new TranslatableMarkup('Session'),
  description: new TranslatableMarkup('Lists the revision, the photo size, the marked regions and the state of the frame.'),
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class SessionGetTool extends LedgerToolBase {

  /**
   * {@inheritdoc}
   */
  protected const STATES = ['start', 'open', 'closed', 'abandoned'];

  /**
   * Describes the game to the player.
   */
  private GameView $gameView;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $tool = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $tool->gameView = $container->get(GameView::class);
    return $tool;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->gameView->view((int) $this->currentUser->id());
  }

}
