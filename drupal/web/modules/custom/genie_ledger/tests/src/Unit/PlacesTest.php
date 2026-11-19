<?php

declare(strict_types=1);

namespace Drupal\Tests\genie_ledger\Unit;

use Drupal\genie_ledger\Photo\Places;
use Drupal\genie_ledger\Game\Session;
use Drupal\genie_ledger\Photo\Survey;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the path from a scan to claimed places.
 */
#[CoversClass(Places::class)]
#[Group('genie_ledger')]
final class PlacesTest extends UnitTestCase {

  /**
   * A scan offers people, a claim takes one, and nobody else can take it.
   */
  public function testScanThenClaim(): void {
    $session = $this->scanned();
    $this->assertCount(2, $session->proposals);
    $red = array_key_first($session->proposals);
    $this->assertSame(450, $session->proposals[$red]['height'], 'A box stops at the edge of the photo.');
    $this->assertSame(['cake'], array_column($session->survey->objects, 'label'));

    Places::claim($session, 2, 'bob', $red, NULL, NULL);
    $this->assertSame('red shirt', $session->regions['person-2']['description']);
    $this->assertSame('bob', $session->players[2]);
    $this->assertCount(1, $session->proposals);

    $this->expectExceptionMessage('bob already holds this place.');
    Places::claim($session, 3, 'carol', NULL, [105, 55, 80, 80], [60, 50, 160, 400]);
  }

  /**
   * A claimed person keeps their id, and a new scan stands behind them.
   */
  public function testIdsSurviveScansAndMovedClaims(): void {
    $session = $this->scanned();
    [$red, $blue] = array_keys($session->proposals);
    Places::claim($session, 2, 'bob', $blue, NULL, NULL);
    Places::scan($session, 1, [['description' => 'again', 'head' => [600, 50, 80, 80], 'body' => [560, 50, 160, 400]]], []);
    $this->assertSame($blue, $session->regions['person-2']['person'], 'A scan leaves a claimed person alone.');
    $this->assertSame([$red, $blue], array_column($session->survey->people, 'id'));

    Places::claim($session, 2, 'bob', $red, NULL, NULL);
    $this->assertSame($red, $session->regions['person-2']['person']);
    $this->assertArrayHasKey($blue, $session->proposals, 'The person given up is on offer again.');
    $this->assertSame(1, $session->proposals[$blue]['rank']);

    Places::dismiss($session, $blue);
    $this->assertSame([$red], array_column($session->survey->people, 'id'));
  }

  /**
   * Builds a session after one scan.
   */
  private function scanned(): Session {
    $session = new Session();
    $session->pin(new Survey(1, 1, 1000, 500, 'off'));
    Places::scan($session, 1, [
      ['description' => 'red shirt', 'head' => [100, 50, 80, 80], 'body' => [60, 50, 160, 480]],
      ['description' => 'blue shirt', 'head' => [600, 50, 80, 80], 'body' => [560, 50, 160, 400]],
    ], [['label' => 'cake', 'box' => [300, 300, 100, 100]]]);
    return $session;
  }

}
