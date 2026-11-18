<?php

declare(strict_types=1);

namespace Drupal\Tests\genie_ledger\Unit;

use Drupal\genie_ledger\Frame;
use Drupal\genie_ledger\Framer;
use Drupal\genie_ledger\Session;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the crop search.
 */
#[CoversClass(Framer::class)]
#[Group('genie_ledger')]
final class FramerTest extends UnitTestCase {

  /**
   * An excluded player falls outside the largest crop that avoids them.
   */
  public function testExcludedPlayerLeavesTheFrame(): void {
    $session = new Session();
    $session->photo = ['width' => 1000, 'height' => 500];
    $session->regions['person-7'] = [
      'kind' => 'person',
      'owner' => 7,
      'label' => 'bob',
      'x' => 0,
      'y' => 100,
      'width' => 200,
      'height' => 200,
    ];
    $this->assertEquals(new Frame(0, 0, 1000, 500), (new Framer())->frame($session));

    $session->wishes[7] = 'exclude';
    $frame = (new Framer())->frame($session);

    $this->assertInstanceOf(Frame::class, $frame);
    $this->assertGreaterThanOrEqual(200, $frame->x);
    $this->assertEquals($frame, (new Framer())->frame($session));
  }

}
