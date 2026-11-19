<?php

declare(strict_types=1);

namespace Drupal\Tests\genie_ledger\Unit;

use Drupal\genie_ledger\Photo\BodyPaint;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * Tests what an assistant may send as paint.
 */
#[CoversClass(BodyPaint::class)]
#[Group('genie_ledger')]
final class BodyPaintTest extends UnitTestCase {

  /**
   * A valid paint keeps its values.
   */
  public function testAcceptsValidPaint(): void {
    $paint = BodyPaint::fromInput(TRUE, ['#ff8800'], [[1, 2, 3, '0'], [4, 5, 6, BodyPaint::ORIGINAL]]);
    $this->assertTrue($paint->pixelate);
    $this->assertFalse($paint->isEmpty());
  }

  /**
   * Bad colors and cells outside the palette are refused.
   */
  #[TestWith([['red'], '0'])]
  #[TestWith([['#ff8800'], '1'])]
  public function testRefusesBadPaint(array $palette, string $cell): void {
    $this->expectException(\InvalidArgumentException::class);
    BodyPaint::fromInput(FALSE, $palette, [[1, 2, 3, $cell]]);
  }

}
