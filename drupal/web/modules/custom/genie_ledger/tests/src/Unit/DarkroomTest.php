<?php

declare(strict_types=1);

namespace Drupal\Tests\genie_ledger\Unit;

use Drupal\genie_ledger\Darkroom;
use Drupal\genie_ledger\Frame;
use Drupal\genie_ledger\Session;
use Drupal\genie_ledger\Sticker;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests sticker rendering.
 */
#[CoversClass(Darkroom::class)]
#[Group('genie_ledger')]
final class DarkroomTest extends UnitTestCase {

  /**
   * A blurred sticker changes its own cells and nothing else.
   */
  public function testStickerStaysInsideItsCells(): void {
    $photo = imagecreatetruecolor(128, 128);
    imagefill($photo, 0, 0, imagecolorallocate($photo, 200, 200, 200));
    ob_start();
    imagejpeg($photo, NULL, 100);
    $jpeg = ob_get_clean();
    $original = imagecreatefromstring($jpeg);

    $session = new Session();
    $session->photo = ['width' => 128, 'height' => 128];
    $session->regions['person-7'] = [
      'kind' => 'person',
      'owner' => 7,
      'label' => 'bob',
      'x' => 32,
      'y' => 32,
      'width' => 64,
      'height' => 64,
      'head' => ['x' => 32, 'y' => 32, 'width' => 64, 'height' => 64],
    ];
    $rows = array_fill(0, Sticker::SIZE, str_repeat('0', 16) . str_repeat(Sticker::EMPTY, 16));
    $session->stickers[7] = Sticker::fromInput($rows, ['#ff0000'], 8);

    $png = (new Darkroom())->render($jpeg, $session, new Frame(0, 0, 128, 128));
    $result = imagecreatefromstring($png);

    $this->assertNotSame(imagecolorat($original, 40, 64), imagecolorat($result, 40, 64), 'A color cell changes.');
    $this->assertSame(imagecolorat($original, 90, 64), imagecolorat($result, 90, 64), 'An empty cell stays.');
    $this->assertSame(imagecolorat($original, 20, 64), imagecolorat($result, 20, 64), 'Outside the region stays.');
  }

}
