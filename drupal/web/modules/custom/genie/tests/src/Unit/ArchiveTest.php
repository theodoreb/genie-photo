<?php

declare(strict_types=1);

namespace Drupal\Tests\genie\Unit;

use Drupal\genie\Photo\Archive;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the turn order of the prepared pictures.
 */
#[CoversClass(Archive::class)]
#[Group('genie')]
final class ArchiveTest extends UnitTestCase {

  /**
   * A picture with an open game waits, and the turn wraps around the list.
   */
  public function testSkipsPictureStillOpen(): void {
    $photos = [['id' => 'bride'], ['id' => 'algiers'], ['id' => 'church']];

    [$position, $photo] = Archive::nextFree($photos, 5, ['church']);
    $this->assertSame('bride', $photo['id']);
    $this->assertSame(7, $position);

    [$position, $photo] = Archive::nextFree($photos, 5, ['church', 'bride', 'algiers']);
    $this->assertNull($photo, 'Every picture is open, so none opens twice.');
    $this->assertSame(5, $position);
  }

}
