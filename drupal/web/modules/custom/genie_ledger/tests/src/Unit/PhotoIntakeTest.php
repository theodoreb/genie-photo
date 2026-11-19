<?php

declare(strict_types=1);

namespace Drupal\Tests\genie_ledger\Unit;

use Drupal\genie_ledger\PhotoIntake;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * Tests the size every stored photo takes.
 */
#[CoversClass(PhotoIntake::class)]
#[Group('genie_ledger')]
final class PhotoIntakeTest extends UnitTestCase {

  /**
   * Small and large photos reach the same long side.
   */
  #[TestWith([800, 400, 1600, 800])]
  #[TestWith([4000, 2000, 1600, 800])]
  #[TestWith([600, 900, 1067, 1600])]
  public function testScalesToTheLongSide(int $width, int $height, int $expectedWidth, int $expectedHeight): void {
    $photo = (new PhotoIntake())->normalize($this->jpeg($width, $height));
    $this->assertSame([$expectedWidth, $expectedHeight], [$photo['width'], $photo['height']]);
  }

  /**
   * Tiny and very narrow photos are refused.
   */
  #[TestWith([400, 300])]
  #[TestWith([1600, 400])]
  public function testRefusesUnusableShapes(int $width, int $height): void {
    $this->expectException(\InvalidArgumentException::class);
    (new PhotoIntake())->normalize($this->jpeg($width, $height));
  }

  /**
   * Builds a blank JPEG.
   */
  private function jpeg(int $width, int $height): string {
    ob_start();
    imagejpeg(imagecreatetruecolor($width, $height));
    return ob_get_clean();
  }

}
