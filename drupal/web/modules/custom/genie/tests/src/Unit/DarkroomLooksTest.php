<?php

declare(strict_types=1);

namespace Drupal\Tests\genie\Unit;

use Drupal\genie\Game\Session;
use Drupal\genie\Photo\BodyPaint;
use Drupal\genie\Photo\Darkroom;
use Drupal\genie\Photo\Outlines;
use Drupal\genie\Photo\Places;
use Drupal\genie\Photo\Survey;
use Drupal\genie\Wish\Frame;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the looks a player wishes for, on a grey photo.
 */
#[CoversClass(Darkroom::class)]
#[Group('genie')]
final class DarkroomLooksTest extends UnitTestCase {

  /**
   * The photo: grey 40 on the left half, grey 220 on the right half.
   */
  private \GdImage $original;

  /**
   * The rendered picture.
   */
  private \GdImage $result;

  /**
   * Renders three players: ann in front, bob hidden behind ann, cy covered.
   */
  private function render(string $annLook, string $bobLook): Session {
    $session = new Session();
    $box = ['x' => 600, 'y' => 0, 'width' => 400, 'height' => 800];
    $session->pin(new Survey(1, 1, 1600, 800, 'ready', [$box, $box]));
    Places::scan($session, 1, [
      [
        'description' => 'ann',
        'head' => [720, 40, 160, 160],
        'detected' => 'P1',
        'outline' => [[5, 18, 21], [6, 18, 21]],
      ],
      [
        'description' => 'bob',
        'head' => [920, 40, 60, 60],
        'detected' => 'P2',
        'outline' => [[5, 18, 21]],
      ],
    ], []);
    [$ann, $bob] = array_keys($session->proposals);
    Places::claim($session, 1, 'ann', $ann, NULL, NULL);
    Places::claim($session, 2, 'bob', $bob, NULL, NULL);
    Places::claim($session, 3, 'cy', NULL, [850, 245, 30, 30], [800, 200, 200, 300]);
    $this->assertSame([], array_keys(Outlines::owners($session), 'person-2', TRUE), 'Ann hides every cell of bob.');
    // One fine row of red on the background cell left of ann.
    $session->paints[1] = BodyPaint::fromInput(FALSE, ['#ff0000'], [[27, 85, 89, '0']]);
    $session->wishes = [1 => $annLook, 2 => $bobLook, 3 => 'cover'];

    $photo = imagecreatetruecolor(1600, 800);
    imagefilledrectangle($photo, 0, 0, 799, 799, imagecolorallocate($photo, 40, 40, 40));
    imagefilledrectangle($photo, 800, 0, 1599, 799, imagecolorallocate($photo, 220, 220, 220));
    ob_start();
    imagejpeg($photo, NULL, 100);
    $jpeg = ob_get_clean();
    $this->original = imagecreatefromstring($jpeg);
    $this->result = imagecreatefromstring((new Darkroom())->render($jpeg, $session, new Frame(0, 0, 1600, 800)));
    return $session;
  }

  /**
   * Returns a rendered pixel as red, green and blue.
   *
   * @return list<int>
   *   The channels.
   */
  private function rgb(int $x, int $y): array {
    $color = imagecolorat($this->result, $x, $y);
    return [$color >> 16 & 255, $color >> 8 & 255, $color & 255];
  }

  /**
   * Asserts a pixel kept its colour from the photo.
   */
  private function assertUnchanged(int $x, int $y, string $message): void {
    $this->assertSame(imagecolorat($this->original, $x, $y), imagecolorat($this->result, $x, $y), $message);
  }

  /**
   * Gold follows brightness on the player's own cells and nowhere else.
   */
  public function testGold(): void {
    $this->render('gold', 'visible');
    [$darkRed, , $darkBlue] = $this->rgb(760, 220);
    [$lightRed, , $lightBlue] = $this->rgb(860, 220);
    $this->assertGreaterThan($darkBlue, $darkRed, 'A dark own pixel turns gold.');
    $this->assertGreaterThan($lightBlue, $lightRed, 'A light own pixel turns gold.');
    $this->assertGreaterThan($darkRed, $lightRed, 'Gold follows brightness.');
    $this->assertSame(0xFF0000, imagecolorat($this->result, 700, 220), 'Paint around the person stays.');
    $this->assertUnchanged(500, 220, 'A pixel away from the person stays.');
    $this->assertUnchanged(950, 70, 'A player without the look stays.');
    $this->assertSame([43, 33, 64], $this->rgb(860, 255), 'A cover wins over the look.');
  }

  /**
   * A person who owns no cell gets the look on the head only.
   */
  public function testLookOnHeadAlone(): void {
    $this->render('visible', 'gold');
    [$red, , $blue] = $this->rgb(950, 70);
    $this->assertGreaterThan($blue, $red, 'The head turns gold.');
    $this->assertUnchanged(990, 70, 'Beside the head stays.');
    $this->assertUnchanged(760, 220, 'The cells in front stay.');
  }

}
