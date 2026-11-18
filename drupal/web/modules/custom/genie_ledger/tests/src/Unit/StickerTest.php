<?php

declare(strict_types=1);

namespace Drupal\Tests\genie_ledger\Unit;

use Drupal\genie_ledger\Sticker;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that miscounted drawings still fit the grid.
 */
#[CoversClass(Sticker::class)]
#[Group('genie_ledger')]
final class StickerTest extends UnitTestCase {

  /**
   * Short rows are padded, long rows are cut and missing rows stay empty.
   */
  public function testInputIsFittedToTheGrid(): void {
    $sticker = Sticker::fromInput(['00', str_repeat('0', 40)], ['#ff0000'], 0);

    $this->assertCount(Sticker::SIZE, $sticker->rows);
    $this->assertSame('00' . str_repeat('.', 30), $sticker->rows[0]);
    $this->assertSame(str_repeat('0', 32), $sticker->rows[1]);
    $this->assertSame(str_repeat('.', 32), $sticker->rows[2]);
  }

  /**
   * A span paints a run of cells and stops at the edge of the grid.
   */
  public function testSpansPaintRuns(): void {
    $sticker = Sticker::fromInput(NULL, ['#ff0000'], 0, [[3, 30, 40, '0']]);

    $this->assertSame(str_repeat('.', 30) . '00', $sticker->rows[3]);
  }

}
