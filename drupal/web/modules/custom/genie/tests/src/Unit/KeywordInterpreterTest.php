<?php

declare(strict_types=1);

namespace Drupal\Tests\genie\Unit;

use Drupal\genie\Wish\KeywordInterpreter;
use Drupal\genie\Wish\Operation;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that a negated wish never grants its opposite.
 */
#[CoversClass(KeywordInterpreter::class)]
#[Group('genie')]
final class KeywordInterpreterTest extends UnitTestCase {

  /**
   * Reads plain and negated wishes.
   */
  public function testNegatedWishes(): void {
    $expected = [
      'hide my face' => Operation::Cover,
      'do not hide me' => Operation::Show,
      "don't show me" => Operation::Cover,
      "I don't want to be seen, hide my face" => Operation::Cover,
      'turn me to gold' => Operation::Gold,
      'make me a golden statue' => Operation::Gold,
      'stop the gold' => Operation::Show,
      'take the gold off me' => Operation::Show,
      'disguise me' => Operation::Disguise,
      'give me sunglasses and a moustache' => Operation::Disguise,
      'put me in disguise' => Operation::Disguise,
      'take off my disguise' => Operation::Show,
      "don't disguise me" => Operation::Show,
      'make me a cartoon' => Operation::Cartoon,
      'draw me like a comic' => Operation::Cartoon,
      'no more cartoon' => Operation::Show,
      "don't make me a cartoon" => Operation::Show,
    ];
    $interpreter = new KeywordInterpreter();
    foreach ($expected as $text => $operation) {
      $this->assertSame($operation, $interpreter->interpret($text)?->operation, $text);
    }
    $this->assertNull($interpreter->interpret('hide my face and make me gold'));
    $this->assertNull($interpreter->interpret("don't disguise me, make me a cartoon"));
    $this->assertNull($interpreter->interpret('put me in the middle'));
  }

}
