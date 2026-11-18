<?php

declare(strict_types=1);

namespace Drupal\Tests\genie_ledger\Unit;

use Drupal\genie_ledger\Places;
use Drupal\genie_ledger\Session;
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
    $session = new Session();
    $session->photo = ['width' => 1000, 'height' => 500];
    Places::scan($session, 1, [
      ['description' => 'red shirt', 'head' => [100, 50, 80, 80], 'body' => [60, 50, 160, 480]],
      ['description' => 'blue shirt', 'head' => [600, 50, 80, 80], 'body' => [560, 50, 160, 400]],
    ], [['label' => 'cake', 'box' => [300, 300, 100, 100]]]);

    $this->assertCount(2, $session->proposals);
    $this->assertSame(450, $session->proposals[array_key_first($session->proposals)]['height'], 'A box stops at the edge of the photo.');
    $this->assertSame('object', $session->regions['object-' . md5('cake')]['kind']);

    Places::claim($session, 2, 'bob', array_key_first($session->proposals), NULL, NULL);
    $this->assertSame('red shirt', $session->regions['person-2']['description']);
    $this->assertSame('bob', $session->players[2]);
    $this->assertCount(1, $session->proposals);

    $this->expectExceptionMessage('bob already holds this place.');
    Places::claim($session, 3, 'carol', NULL, [105, 55, 80, 80], [60, 50, 160, 400]);
  }

}
