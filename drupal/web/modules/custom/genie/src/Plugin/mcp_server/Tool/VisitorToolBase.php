<?php

declare(strict_types=1);

namespace Drupal\genie\Plugin\mcp_server\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\genie\Game\CurrentGame;
use Drupal\genie\Game\GameView;
use Drupal\genie\Game\Games;
use Drupal\genie\Web\WebViewAccess;
use Drupal\mcp_server\Plugin\ToolPluginBase;
use Mcp\Server\Exception\ToolCallException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base for the tools that show any game to the web view's own token.
 *
 * That token belongs to no player, so these tools only read.
 */
abstract class VisitorToolBase extends ToolPluginBase {

  final public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    AccountProxyInterface $currentUser,
    protected readonly Games $games,
    protected readonly CurrentGame $game,
    protected readonly GameView $gameView,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $currentUser);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user'),
      $container->get(Games::class),
      $container->get(CurrentGame::class),
      $container->get(GameView::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function defaultConfiguration(): array {
    return ['enabled' => TRUE];
  }

  /**
   * {@inheritdoc}
   */
  public function checkAccess(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIf($account->isAuthenticated() && $account->hasPermission('view genie games'))
      ->andIf((new WebViewAccess())->access($account));
  }

  /**
   * Makes the game with this UUID the current one.
   *
   * @throws \Mcp\Server\Exception\ToolCallException
   *   No game has this UUID, or the game has no photo yet.
   */
  protected function enter(mixed $uuid): void {
    $game = is_string($uuid) ? $this->games->byUuid($uuid) : NULL;
    if ($game === NULL || $game->get('field_genie_photo')->isEmpty()) {
      throw new ToolCallException('There is no such game.');
    }
    $this->game->enter((int) $game->id());
  }

}
