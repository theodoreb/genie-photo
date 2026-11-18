<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Plugin\mcp_server\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\genie_ledger\Ledger;
use Drupal\genie_ledger\WishDesk;
use Drupal\mcp_server\Plugin\ToolPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base for ledger tools: only signed-in players see or call them.
 */
abstract class LedgerToolBase extends ToolPluginBase {

  final public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    AccountProxyInterface $currentUser,
    protected readonly Ledger $ledger,
    protected readonly WishDesk $desk,
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
      $container->get(Ledger::class),
      $container->get(WishDesk::class),
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
    return AccessResult::allowedIf($account->isAuthenticated() && $account->hasPermission('play genie'))
      ->cachePerPermissions();
  }

}
