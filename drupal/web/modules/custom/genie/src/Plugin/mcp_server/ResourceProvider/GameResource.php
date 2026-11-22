<?php

declare(strict_types=1);

namespace Drupal\genie\Plugin\mcp_server\ResourceProvider;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\genie\Game\GameView;
use Drupal\genie\Game\Ledger;
use Drupal\mcp_server\Attribute\ResourceProvider;
use Drupal\mcp_server\Plugin\ResourceProviderBase;
use Drupal\mcp_server\Resource\CacheableResourceContent;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The player's game, as session_get describes it. Subscribe to follow it.
 */
#[ResourceProvider(
  id: 'genie_game',
  label: new TranslatableMarkup('Genie game'),
  description: new TranslatableMarkup('The game you play. Subscribe to it to learn when the picture changes.'),
)]
final class GameResource extends ResourceProviderBase {

  private const PREFIX = 'genie://game/';

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    EntityTypeManagerInterface $entityTypeManager,
    AccountProxyInterface $currentUser,
    private readonly Ledger $ledger,
    private readonly GameView $gameView,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entityTypeManager, $currentUser);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get(Ledger::class),
      $container->get(GameView::class),
    );
  }

  /**
   * Names the resource of a game node.
   */
  public static function uri(string $uuid): string {
    return self::PREFIX . $uuid;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['enabled' => TRUE] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function getResources(): array {
    if ($this->ledger->state() === NULL) {
      return [];
    }
    return [
      [
        'uri' => self::uri($this->ledger->game()->uuid()),
        'name' => 'Your genie game',
        'description' => 'The places, wishes, picture state and approvals of your game, as session_get returns them.',
        'mimeType' => 'application/json',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getResourceContent(string $uri): CacheableResourceContent {
    $view = $this->gameView->view((int) $this->currentUser->id());
    return CacheableResourceContent::fromArray(
      ['uri' => $uri, 'mimeType' => 'application/json', 'text' => json_encode($view, JSON_THROW_ON_ERROR)],
      (new CacheableMetadata())->setCacheMaxAge(0),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function checkAccess(string $uri, AccountInterface $account): AccessResultInterface {
    // Only the game the account belongs to: another game's uuid is refused.
    return AccessResult::allowedIf(
      $account->hasPermission('play genie')
      && $this->ledger->state() !== NULL
      && $uri === self::uri($this->ledger->game()->uuid()),
    )->setCacheMaxAge(0);
  }

}
