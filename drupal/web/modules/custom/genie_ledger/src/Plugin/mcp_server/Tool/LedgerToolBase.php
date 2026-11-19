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
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base for ledger tools: only signed-in players see or call them.
 */
abstract class LedgerToolBase extends ToolPluginBase {

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
    // The server is built per request, so the state is read for each one.
    return AccessResult::allowedIf($account->isAuthenticated()
      && $account->hasPermission('play genie')
      && in_array($this->ledger->state(), static::STATES, TRUE));
  }

  /**
   * Builds a result holding a description and an image.
   *
   * It carries no structured content, because some clients show only that and
   * would drop the image.
   *
   * @param array{bytes: string, mime: string, info: array<string, mixed>} $image
   *   The image and what to say about it.
   *
   * @return list<\Mcp\Schema\Content\Content>
   *   The content of the result.
   */
  protected function imageResult(array $image): array {
    return [
      new TextContent(json_encode($image['info'], JSON_THROW_ON_ERROR)),
      new ImageContent(base64_encode($image['bytes']), $image['mime']),
    ];
  }

}
