<?php

declare(strict_types=1);

namespace Drupal\Tests\genie\Unit;

use Drupal\genie\Game\Session;
use Drupal\genie\Photo\BodyPaint;
use Drupal\genie\Photo\Darkroom;
use Drupal\genie\Photo\Outlines;
use Drupal\genie\Photo\Places;
use Drupal\genie\Photo\Survey;
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
   * The photo: stripes of grey 160, 100, 40 and 220 that meet across ann.
   */
  private \GdImage $original;

  /**
   * The rendered picture.
   */
  private \GdImage $result;

  /**
   * Renders three players: ann in front, bob hidden behind ann, cy covered.
   */
  private function render(string $annLook, string $bobLook, array $bobHead = [920, 40, 60, 60], string $cyLook = 'cover'): Session {
    $session = new Session();
    $box = ['x' => 600, 'y' => 0, 'width' => 400, 'height' => 800];
    $session->pin(new Survey(1, 1, 1600, 800, 'ready', [$box, $box]));
    Places::scan($session, [
      [
        'description' => 'ann',
        'head' => [720, 40, 160, 160],
        'detected' => 'P1',
        'outline' => [[5, 18, 21], [6, 18, 21]],
      ],
      [
        'description' => 'bob',
        'head' => $bobHead,
        'detected' => 'P2',
        'outline' => [[5, 18, 21]],
      ],
    ]);
    [$ann, $bob] = array_keys($session->proposals);
    Places::claim($session, 1, 'ann', $ann, NULL, NULL);
    Places::claim($session, 2, 'bob', $bob, NULL, NULL);
    Places::claim($session, 3, 'cy', NULL, [850, 245, 30, 30], [800, 200, 200, 300]);
    $this->assertSame([], array_keys(Outlines::owners($session), 'person-2', TRUE), 'Ann hides every cell of bob.');
    // One fine row of red on the background cell left of ann.
    $session->paints[1] = BodyPaint::fromInput(FALSE, ['#ff0000'], [[27, 85, 89, '0']]);
    $session->wishes = [1 => $annLook, 2 => $bobLook, 3 => $cyLook];

    $photo = imagecreatetruecolor(1600, 800);
    foreach ([[0, 759, 160], [760, 799, 100], [800, 839, 40], [840, 1599, 220]] as [$left, $right, $grey]) {
      imagefilledrectangle($photo, $left, 0, $right, 799, imagecolorallocate($photo, $grey, $grey, $grey));
    }
    ob_start();
    imagejpeg($photo, NULL, 100);
    $jpeg = ob_get_clean();
    $this->original = imagecreatefromstring($jpeg);
    $this->result = imagecreatefromstring((new Darkroom())->render($jpeg, $session));
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
    [$darkRed, , $darkBlue] = $this->rgb(740, 220);
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
   * The disguise sits in the head box only.
   */
  public function testDisguise(): void {
    $this->render('disguise', 'visible');
    $this->assertSame(0xFFFFFF, imagecolorat($this->result, 745, 115), 'The glint is white.');
    $this->assertSame(0x0F0F0F, imagecolorat($this->result, 735, 115), 'The lens is dark.');
    $this->assertSame(0x231914, imagecolorat($this->result, 785, 155), 'The moustache is dark.');
    $this->assertUnchanged(700, 115, 'Beside the head stays.');
    $this->assertUnchanged(760, 75, 'The forehead stays.');
  }

  /**
   * A head smaller than the disguise grid keeps the disguise inside it.
   */
  public function testDisguiseOnTinyHead(): void {
    $this->render('visible', 'disguise', [920, 40, 10, 10]);
    foreach (range(916, 933) as $x) {
      foreach ([36, 37, 38, 39, 50, 51, 52, 53] as $y) {
        $this->assertUnchanged($x, $y, "Above and below the head stay at $x, $y.");
      }
    }
    foreach (range(36, 53) as $y) {
      foreach ([916, 917, 918, 919, 930, 931, 932, 933] as $x) {
        $this->assertUnchanged($x, $y, "Beside the head stays at $x, $y.");
      }
    }
  }

  /**
   * The cartoon turns own pixels black or white, with a pattern per band.
   */
  public function testCartoon(): void {
    $this->render('cartoon', 'visible');
    $inks = [];
    foreach (range(720, 879) as $x) {
      foreach (range(200, 279) as $y) {
        // Cy's head is covered.
        if ($x >= 850 && $y >= 245 && $y < 275) {
          continue;
        }
        $color = imagecolorat($this->result, $x, $y);
        $this->assertContains($color, [0x000000, 0xFFFFFF], "Own pixel $x, $y is black or white.");
        // Smoothing blends a few pixels across each stripe border.
        if (($x - 720) % 40 >= 6 && ($x - 720) % 40 < 34) {
          $stripe = intdiv($x - 720, 40);
          $inks[$stripe][$color] = ($inks[$stripe][$color] ?? 0) + 1;
        }
      }
    }
    $this->assertArrayNotHasKey(0xFFFFFF, $inks[2], 'The darkest band is solid black.');
    $this->assertGreaterThan(0, $inks[1][0xFFFFFF] ?? 0, 'The hatch band has white.');
    $this->assertGreaterThan($inks[1][0xFFFFFF], $inks[0][0xFFFFFF], 'The dot band is lighter than the hatch band.');
    $this->assertGreaterThan(0, $inks[0][0x000000] ?? 0, 'The dot band has black.');
    foreach (range(846, 874) as $x) {
      $this->assertSame(0xFFFFFF, imagecolorat($this->result, $x, 220), "The lightest band is white at $x.");
    }
    // Row 215 holds no halftone dot, and the hatch reaches x 840, so black
    // past it is the edge line.
    $edge = array_filter(range(841, 845), fn (int $x): bool => imagecolorat($this->result, $x, 215) === 0);
    $this->assertNotEmpty($edge, 'A steep step gets a black line on its light side.');
    $this->assertSame(0xFF0000, imagecolorat($this->result, 700, 220), 'Paint around the person stays.');
    $this->assertUnchanged(500, 220, 'A pixel away from the person stays.');
  }

  /**
   * A person with no traced outline gets the look inside an ellipse.
   */
  public function testUntracedPersonGetsAnEllipse(): void {
    $this->render('visible', 'visible', cyLook: 'gold');
    [$red, , $blue] = $this->rgb(920, 350);
    $this->assertGreaterThan($blue, $red, 'The middle of the box turns gold.');
    $this->assertUnchanged(990, 470, 'A corner of the box stays.');
  }

  /**
   * An untraced person left with the corners of the box keeps the head.
   */
  public function testEllipseWithNoRowLeft(): void {
    $session = new Session();
    $box = ['x' => 600, 'y' => 0, 'width' => 400, 'height' => 800];
    $session->pin(new Survey(1, 1, 1600, 800, 'ready', [$box]));
    // The front person covers the whole box but its four corner cells.
    $outline = array_map(
      static fn (int $row): array => in_array($row, [0, 1, 18, 19], TRUE) ? [$row, 16, 23] : [$row, 15, 24],
      range(0, 19),
    );
    Places::scan($session, [
      ['description' => 'front', 'head' => [740, 0, 120, 120], 'detected' => 'P1', 'outline' => $outline],
    ]);
    Places::claim($session, 1, 'ann', array_key_first($session->proposals), NULL, NULL);
    Places::claim($session, 2, 'cy', NULL, [610, 10, 20, 20], [600, 0, 400, 800]);
    $corners = [15, 24, 55, 64, 735, 744, 775, 784];
    $this->assertSame($corners, array_keys(Outlines::owners($session), 'person-2', TRUE), 'Cy owns only the corners.');
    $session->wishes = [2 => 'cartoon'];
    $photo = imagecreatetruecolor(1600, 800);
    imagefill($photo, 0, 0, imagecolorallocate($photo, 220, 220, 220));
    ob_start();
    imagejpeg($photo, NULL, 100);
    $jpeg = ob_get_clean();
    $result = imagecreatefromstring((new Darkroom())->render($jpeg, $session));
    $this->assertSame(0xFFFFFF, imagecolorat($result, 620, 20), 'The head is drawn.');
  }

  /**
   * A guess in front of an untraced person takes no cell, and a player does.
   *
   * The guess is unclaimed and has no outline. The player is claimed.
   */
  public function testGuessedBoxTakesNoCell(): void {
    $session = new Session();
    $dan = [
      'id' => 'dan',
      'description' => 'dan',
      'x' => 880,
      'y' => 200,
      'width' => 80,
      'height' => 300,
      'head' => ['x' => 900, 'y' => 210, 'width' => 30, 'height' => 30],
      'cells' => [],
    ];
    $session->pin(new Survey(1, 1, 1600, 800, 'off', [], [$dan]));
    Places::claim($session, 2, 'fay', NULL, [805, 205, 30, 30], [800, 200, 60, 300]);
    Places::claim($session, 1, 'cy', NULL, [850, 245, 30, 30], [800, 200, 200, 300]);
    $session->wishes = [1 => 'gold'];
    $result = $this->develop($session, [[0, 1599, 128]]);
    $color = imagecolorat($result, 920, 350);
    $this->assertGreaterThan($color & 255, $color >> 16 & 255, 'The middle of the box under the guess turns gold.');
    $fay = imagecolorat($result, 820, 350);
    $this->assertSame($fay & 255, $fay >> 16 & 255, 'Fay stays grey.');
  }

  /**
   * On a light photo, each quarter of the person gets its own band.
   */
  public function testCartoonSplitsLightPhotoInQuarters(): void {
    $session = new Session();
    $session->pin(new Survey(1, 1, 1600, 800, 'off'));
    Places::scan($session, [
      [
        'description' => 'eve',
        'head' => [740, 40, 120, 120],
        'body' => [720, 0, 160, 400],
        'outline' => [[5, 18, 21], [6, 18, 21]],
      ],
    ]);
    Places::claim($session, 1, 'eve', array_key_first($session->proposals), NULL, NULL);
    $session->wishes = [1 => 'cartoon'];
    // Four light stripes, one under each quarter of eve.
    $result = $this->develop($session, [[0, 759, 150], [760, 799, 175], [800, 839, 200], [840, 1599, 225]]);
    foreach (range(210, 270, 10) as $y) {
      foreach (range(730, 750, 10) as $x) {
        $this->assertSame(0x000000, imagecolorat($result, $x, $y), "The darkest quarter is solid black at $x, $y.");
      }
      foreach (range(850, 870, 10) as $x) {
        $this->assertSame(0xFFFFFF, imagecolorat($result, $x, $y), "The lightest quarter is white at $x, $y.");
      }
    }
  }

  /**
   * Renders a session on a photo of grey stripes.
   *
   * @param \Drupal\genie\Game\Session $session
   *   The session.
   * @param list<array{int, int, int}> $stripes
   *   The first column, last column and grey of each stripe.
   */
  private function develop(Session $session, array $stripes): \GdImage {
    $photo = imagecreatetruecolor(1600, 800);
    foreach ($stripes as [$left, $right, $grey]) {
      imagefilledrectangle($photo, $left, 0, $right, 799, imagecolorallocate($photo, $grey, $grey, $grey));
    }
    ob_start();
    imagejpeg($photo, NULL, 100);
    return imagecreatefromstring((new Darkroom())->render(ob_get_clean(), $session));
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
