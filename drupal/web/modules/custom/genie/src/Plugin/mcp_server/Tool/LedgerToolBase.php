<?php

declare(strict_types=1);

namespace Drupal\genie\Plugin\mcp_server\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\genie\Game\Ledger;
use Drupal\genie\Web\WebViewAccess;
use Drupal\mcp_server\Plugin\ToolPluginBase;
use Mcp\Server\Exception\ToolCallException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base for ledger tools: only signed-in players see or call them.
 */
abstract class LedgerToolBase extends ToolPluginBase {

  use ImageResultTrait;

  /**
   * The game states in which the tool is listed.
   */
  protected const STATES = ['open'];

  final public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    AccountProxyInterface $currentUser,
    protected readonly Ledger $ledger,
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
    // The server is built per request, so the state is read for each one.
    return AccessResult::allowedIf($account->isAuthenticated()
      && $account->hasPermission('play genie')
      && in_array($this->ledger->state(), static::STATES, TRUE));
  }

  /**
   * Refuses an assistant while someone in the photo has no outline.
   *
   * The web view's own client claims for a visitor on the page, where nobody
   * can trace, so it passes.
   *
   * @throws \Mcp\Server\Exception\ToolCallException
   *   When someone is untraced.
   */
  protected function requireTraced(): void {
    if ((new WebViewAccess())->access($this->currentUser)->isAllowed()) {
      return;
    }
    $session = $this->ledger->read();
    if ($session->untraced() !== []) {
      throw new ToolCallException($session->next((int) $this->currentUser->id()));
    }
  }

}
