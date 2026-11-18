<?php

declare(strict_types=1);

namespace Drupal\Tests\genie_ledger\Unit;

use Drupal\genie_ledger\BodyPaint;
use Drupal\genie_ledger\Darkroom;
use Drupal\genie_ledger\Frame;
use Drupal\genie_ledger\Outlines;
use Drupal\genie_ledger\Places;
use Drupal\genie_ledger\Session;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests who owns each cell of the photo.
 */
#[CoversClass(Outlines::class)]
#[Group('genie_ledger')]
final class OutlinesTest extends UnitTestCase {

  /**
   * The person in front keeps a shared cell, and paint stays on owned cells.
   */
  public function testFrontPersonKeepsSharedCellsFromPaint(): void {
    $session = new Session();
    $session->photo = ['width' => 400, 'height' => 200];
    $session->detection = [
      'status' => 'ready',
      'photo' => 'x',
      'boxes' => [
        ['x' => 0, 'y' => 0, 'width' => 200, 'height' => 200],
        ['x' => 100, 'y' => 0, 'width' => 200, 'height' => 200],
      ],
    ];
    $cell = Outlines::cellSize($session->photo);
    $this->assertSame(10, $cell);
    Places::scan($session, 1, [
      [
        'description' => 'front',
        'head' => [120, 0, 40, 40],
        'detected' => 'P2',
        'outline' => [[5, 10, 14], [5, 90, 99]],
      ],
      ['description' => 'back', 'head' => [20, 0, 40, 40], 'detected' => 'P1', 'outline' => [[5, 8, 12]]],
    ], []);
    [$front, $back] = array_keys($session->proposals);
    $this->assertSame([[5, 10, 14]], $session->proposals[$front]['cells'], 'Cells far from the box are dropped.');

    Places::claim($session, 1, 'ann', $front, NULL, NULL);
    Places::claim($session, 2, 'bob', $back, [25, 5, 30, 30], NULL);
    $this->assertSame(25, $session->regions['person-2']['head']['x']);
    $owners = Outlines::owners($session);
    $columns = Outlines::columns($session->photo);
    $this->assertSame('person-1', $owners[5 * $columns + 11], 'The front person wins.');
    $this->assertSame('person-2', $owners[5 * $columns + 9]);
    $this->assertSame([[25, 29, 40, 49]], Outlines::editable(array_keys($owners, 'person-2', TRUE), $session->photo));

    $session->paints[2] = BodyPaint::fromInput(FALSE, ['#ff0000'], [[27, 40, 69, '0']]);
    $photo = imagecreatetruecolor(400, 200);
    imagefill($photo, 0, 0, imagecolorallocate($photo, 200, 200, 200));
    ob_start();
    imagejpeg($photo, NULL, 100);
    $jpeg = ob_get_clean();
    $original = imagecreatefromstring($jpeg);
    $result = imagecreatefromstring((new Darkroom())->render($jpeg, $session, new Frame(0, 0, 400, 200)));

    $this->assertSame(0xFF0000, imagecolorat($result, 85, 55), 'The owned cell is painted.');
    $this->assertSame(imagecolorat($original, 115, 55), imagecolorat($result, 115, 55), 'The cell of the person in front stays.');
  }

}
