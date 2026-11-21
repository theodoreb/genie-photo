<?php

declare(strict_types=1);

namespace Drupal\Tests\genie\Unit;

use Drupal\genie\Photo\Places;
use Drupal\genie\Game\Session;
use Drupal\genie\Photo\Survey;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the path from a scan to claimed places.
 */
#[CoversClass(Places::class)]
#[Group('genie')]
final class PlacesTest extends UnitTestCase {

  /**
   * A scan offers people, a claim takes one, and nobody else can take it.
   */
  public function testScanThenClaim(): void {
    $session = $this->scanned();
    $this->assertCount(2, $session->proposals);
    $red = array_key_first($session->proposals);
    $this->assertSame(450, $session->proposals[$red]['height'], 'A box stops at the edge of the photo.');

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
    Places::scan($session, [
      ['description' => 'again', 'head' => [600, 50, 80, 80], 'body' => [560, 50, 160, 400], 'outline' => [[2, 14, 17]]],
    ]);
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
   * A scan refuses a person with no outline, who would hold everyone back.
   */
  public function testScanRefusesUntracedPerson(): void {
    $session = $this->scanned();
    $this->expectExceptionMessage('Trace green coat');
    $green = ['description' => 'green coat', 'head' => [800, 50, 80, 80], 'body' => [780, 50, 160, 400]];
    Places::scan($session, [$green]);
  }

  /**
   * A scan traces a claimed place inside its own box, and keeps its boxes.
   */
  public function testScanTracesClaimedPlace(): void {
    $session = $this->scanned();
    $this->assertSame([], $session->untraced());
    Places::claim($session, 2, 'bob', NULL, [800, 50, 80, 80], [780, 50, 160, 400]);
    $this->assertSame(['bob'], $session->untraced());
    $this->assertStringStartsWith('Trace every person', $session->next(2));

    // The scan sends a body over the whole photo, and the place keeps its own.
    $trace = [
      'description' => 'green',
      'head' => [800, 50, 80, 80],
      'body' => [0, 0, 1000, 500],
      'outline' => [[2, 0, 24]],
    ];
    Places::scan($session, [$trace]);
    $region = $session->regions['person-2'];
    $this->assertSame([[2, 18, 24]], $region['cells'], 'The outline stays inside the place.');
    $this->assertSame([780, 160], [$region['x'], $region['width']], 'The place keeps its body.');
    $this->assertSame([], $session->untraced());

    Places::scan($session, [['outline' => [[3, 19, 22]]] + $trace]);
    $this->assertSame([[2, 18, 24]], $session->regions['person-2']['cells'], 'A traced place keeps its outline.');
  }

  /**
   * Builds a session after one scan.
   */
  private function scanned(): Session {
    $session = new Session();
    $session->pin(new Survey(1, 1, 1000, 500, 'off'));
    Places::scan($session, [
      [
        'description' => 'red shirt',
        'head' => [100, 50, 80, 80],
        'body' => [60, 50, 160, 480],
        'outline' => [[3, 2, 4]],
      ],
      [
        'description' => 'blue shirt',
        'head' => [600, 50, 80, 80],
        'body' => [560, 50, 160, 400],
        'outline' => [[2, 14, 17]],
      ],
    ]);
    return $session;
  }

}
