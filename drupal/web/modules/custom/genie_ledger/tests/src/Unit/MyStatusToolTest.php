<?php

declare(strict_types=1);

namespace Drupal\Tests\genie_ledger\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\genie_ledger\Framer;
use Drupal\genie_ledger\KeywordInterpreter;
use Drupal\genie_ledger\Ledger;
use Drupal\genie_ledger\Plugin\mcp_server\Tool\MyStatusTool;
use Drupal\genie_ledger\RevisionPublisher;
use Drupal\genie_ledger\WishDesk;
use Drupal\Tests\UnitTestCase;
use Mcp\Server\ClientGateway;
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
   * Builds the tool for a current user.
   */
  private function tool(?AccountProxyInterface $currentUser = NULL): MyStatusTool {
    $keyValue = $this->createStub(KeyValueFactoryInterface::class);
    $keyValue->method('get')->willReturn($this->createStub(KeyValueStoreInterface::class));
    $ledger = new Ledger($keyValue, $this->createStub(LockBackendInterface::class), new RevisionPublisher());
    return new MyStatusTool([], 'my_status', NULL, $currentUser ?? $this->createStub(AccountProxyInterface::class), $ledger, new WishDesk($ledger, new Framer(), new KeywordInterpreter()));
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
    $currentUser->method('getAccountName')->willReturn('alice');

    $result = $this->tool($currentUser)->execute([], $this->createStub(ClientGateway::class));

    $this->assertSame(['uid' => 7, 'name' => 'alice'], $result);
  }

  /**
   * Only signed-in accounts with the permission reach the tool.
   */
  public function testAccess(): void {
    $this->assertTrue($this->tool()->checkAccess($this->account(TRUE, TRUE))->isAllowed());
    $this->assertFalse($this->tool()->checkAccess($this->account(TRUE, FALSE))->isAllowed());
    $this->assertFalse($this->tool()->checkAccess($this->account(FALSE, TRUE))->isAllowed());
  }

}
