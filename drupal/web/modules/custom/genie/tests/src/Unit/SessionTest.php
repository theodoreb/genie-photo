<?php

declare(strict_types=1);

namespace Drupal\Tests\genie\Unit;

use Drupal\genie\Game\Session;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that a game stored before framing was removed still loads.
 */
#[CoversClass(Session::class)]
#[Group('genie')]
final class SessionTest extends UnitTestCase {

  /**
   * Drops removed keys and removed wishes, and keeps the rest.
   */
  public function testStoredSessionWithRemovedWishes(): void {
    $stored = json_encode([
      'revision' => 7,
      'surveyRevision' => 3,
      'paints' => [],
      'claims' => [],
      'wishes' => [1 => 'exclude', 2 => 'center_on', 3 => 'stop_center', 4 => 'protect', 5 => 'gold', 6 => 'cover'],
      'centered' => [2 => TRUE],
      'protected' => ['object-1' => 'ann'],
      'suggestions' => [],
      'players' => [5 => 'eve'],
      'update' => 9,
      'approvals' => [],
    ], JSON_THROW_ON_ERROR);
    $field = new \stdClass();
    $field->value = $stored;
    $game = $this->createMock(NodeInterface::class);
    $game->method('get')->with('field_genie_session')->willReturn($field);
    $game->method('getOwnerId')->willReturn('5');

    $session = Session::fromGame($game);

    $this->assertSame([5 => 'gold', 6 => 'cover'], $session->wishes);
    $this->assertSame(7, $session->revision);
    $this->assertArrayNotHasKey('centered', json_decode($session->toJson(), TRUE));
  }

}
