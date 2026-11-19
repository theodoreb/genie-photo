<?php

declare(strict_types=1);

namespace Drupal\Tests\genie_ledger\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\genie_ledger\CurrentGame;
use Drupal\genie_ledger\Ledger;
use Drupal\genie_ledger\PhotoStore;
use Drupal\genie_ledger\Plugin\mcp_server\Tool\MyStatusTool;
use Drupal\genie_ledger\RevisionPublisher;
use Drupal\genie_ledger\WishDesk;
use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeInterface;
use Mcp\Server\ClientGateway;
use Mcp\Server\Subscription\NotificationBusInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the my_status tool.
 */
#[CoversClass(MyStatusTool::class)]
#[Group('genie_ledger')]
final class MyStatusToolTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $contexts = $this->createStub(CacheContextsManager::class);
    $contexts->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $contexts);
    \Drupal::setContainer($container);
  }

  /**
   * Builds the tool for a current user, in a game that is open or none.
   */
  private function tool(?AccountProxyInterface $currentUser = NULL, ?string $state = 'open'): MyStatusTool {
    $currentUser ??= $this->createStub(AccountProxyInterface::class);
    $stateField = $this->createStub(FieldItemListInterface::class);
    $stateField->method('__get')->willReturn($state);
    $node = $this->createStub(NodeInterface::class);
    $node->method('get')->willReturn($stateField);
    $nodes = $this->createStub(EntityStorageInterface::class);
    $nodes->method('loadUnchanged')->willReturn($node);
    $entityTypeManager = $this->createStub(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturn($nodes);
    $game = new CurrentGame($currentUser, $entityTypeManager);
    if ($state !== NULL) {
      $game->enter(1);
    }
    $photos = (new \ReflectionClass(PhotoStore::class))->newInstanceWithoutConstructor();
    $ledger = new Ledger($entityTypeManager, $this->createStub(LockBackendInterface::class), new RevisionPublisher(), $photos, $game, $this->createStub(NotificationBusInterface::class));
    $desk = (new \ReflectionClass(WishDesk::class))->newInstanceWithoutConstructor();
    return new MyStatusTool([], 'my_status', NULL, $currentUser, $ledger, $desk);
  }

  /**
   * Builds an account.
   */
  private function account(bool $authenticated, bool $mayPlay): AccountInterface {
    $account = $this->createStub(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn($authenticated);
    $account->method('hasPermission')->willReturn($mayPlay);
    return $account;
  }

  /**
   * A player learns their own account name.
   */
  public function testReportsTheCurrentPlayer(): void {
    $currentUser = $this->createStub(AccountProxyInterface::class);
    $currentUser->method('id')->willReturn('7');
    $currentUser->method('getDisplayName')->willReturn('alice');

    $result = $this->tool($currentUser)->execute([], $this->createStub(ClientGateway::class));

    $this->assertSame(['uid' => 7, 'name' => 'alice'], $result);
  }

  /**
   * Only signed-in players of a game reach the tool, in every game state.
   */
  public function testAccess(): void {
    $this->assertTrue($this->tool()->checkAccess($this->account(TRUE, TRUE))->isAllowed());
    $this->assertTrue($this->tool(state: 'closed')->checkAccess($this->account(TRUE, TRUE))->isAllowed());
    $this->assertFalse($this->tool()->checkAccess($this->account(TRUE, FALSE))->isAllowed());
    $this->assertFalse($this->tool()->checkAccess($this->account(FALSE, TRUE))->isAllowed());
  }

}
