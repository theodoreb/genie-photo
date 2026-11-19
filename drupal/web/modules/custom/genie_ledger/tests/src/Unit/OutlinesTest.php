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
    $session->photo = ['width' => 1600, 'height' => 800];
    $session->detection = [
      'status' => 'ready',
      'photo' => 'x',
      'boxes' => [
        ['x' => 0, 'y' => 0, 'width' => 800, 'height' => 800],
        ['x' => 400, 'y' => 0, 'width' => 800, 'height' => 800],
      ],
    ];
    $this->assertSame(40, Outlines::columns($session->photo));
    Places::scan($session, 1, [
      [
        'description' => 'front',
        'head' => [480, 0, 160, 160],
        'detected' => 'P2',
        'outline' => [[5, 10, 14], [5, 38, 39]],
      ],
      ['description' => 'back', 'head' => [80, 0, 160, 160], 'detected' => 'P1', 'outline' => [[5, 8, 12]]],
    ], []);
    [$front, $back] = array_keys($session->proposals);
    $this->assertSame([[5, 10, 14]], $session->proposals[$front]['cells'], 'Cells far from the box are dropped.');

    Places::claim($session, 1, 'ann', $front, NULL, NULL);
    Places::claim($session, 2, 'bob', $back, [100, 20, 120, 120], NULL);
    $this->assertSame(100, $session->regions['person-2']['head']['x']);
    $owners = Outlines::owners($session);
    $columns = Outlines::columns($session->photo);
    $this->assertSame('person-1', $owners[5 * $columns + 11], 'The front person wins.');
    $this->assertSame('person-2', $owners[5 * $columns + 9]);
    $this->assertSame([[25, 29, 40, 49]], Outlines::editable(array_keys($owners, 'person-2', TRUE), $session->photo));

    $session->paints[2] = BodyPaint::fromInput(FALSE, ['#ff0000'], [[27, 40, 69, '0']]);
    $photo = imagecreatetruecolor(1600, 800);
    imagefill($photo, 0, 0, imagecolorallocate($photo, 200, 200, 200));
    ob_start();
    imagejpeg($photo, NULL, 100);
    $jpeg = ob_get_clean();
    $original = imagecreatefromstring($jpeg);
    $result = imagecreatefromstring((new Darkroom())->render($jpeg, $session, new Frame(0, 0, 1600, 800)));

    $this->assertSame(0xFF0000, imagecolorat($result, 340, 220), 'The owned cell is painted.');
    $this->assertSame(imagecolorat($original, 420, 220), imagecolorat($result, 420, 220), 'The cell of the person in front stays.');
  }

}
