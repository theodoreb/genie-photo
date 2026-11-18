<?php

declare(strict_types=1);

namespace Drupal\Tests\genie_ledger\Unit;

use Drupal\genie_ledger\BoxProposer;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the guards around the vision model.
 */
#[CoversClass(BoxProposer::class)]
#[Group('genie_ledger')]
final class BoxProposerTest extends UnitTestCase {

  /**
   * The photo only goes to an address on this machine.
   */
  public function testOnlyLoopbackIsAccepted(): void {
    $this->assertTrue(BoxProposer::isLoopback('http://127.0.0.1:11434'));
    $this->assertFalse(BoxProposer::isLoopback('https://ollama.example.com'));
    $this->assertFalse(BoxProposer::isLoopback('http://127.0.0.1.example.com'));
  }

  /**
   * Boxes outside the image are dropped and the others are scaled.
   */
  public function testBoxesAreScaledAndChecked(): void {
    $good = ['kind' => 'person', 'x' => 500, 'y' => 0, 'width' => 100, 'height' => 500];
    $outside = ['x' => 950] + $good;

    $this->assertSame(
      [['kind' => 'person', 'x' => 800, 'y' => 0, 'width' => 160, 'height' => 400]],
      BoxProposer::toPixels([$good, $outside, 'nonsense'], 1600, 800),
    );
  }

}
