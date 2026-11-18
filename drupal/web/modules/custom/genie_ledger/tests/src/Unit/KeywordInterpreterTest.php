<?php

declare(strict_types=1);

namespace Drupal\Tests\genie_ledger\Unit;

use Drupal\genie_ledger\KeywordInterpreter;
use Drupal\genie_ledger\Operation;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that a negated wish never grants its opposite.
 */
#[CoversClass(KeywordInterpreter::class)]
#[Group('genie_ledger')]
final class KeywordInterpreterTest extends UnitTestCase {

  /**
   * Reads plain and negated wishes.
   */
  public function testNegatedWishes(): void {
    $expected = [
      'crop me out' => Operation::Exclude,
      "don't crop me out" => Operation::Show,
      'do not remove me' => Operation::Show,
      "don't leave me out" => Operation::Show,
      'hide my face' => Operation::Cover,
      'put me in the middle' => Operation::Center,
      'put me back in the middle' => Operation::Center,
      "don't put me in the middle" => Operation::StopCenter,
      'take me out of the middle' => Operation::StopCenter,
      'I no longer want to be in the middle' => Operation::StopCenter,
    ];
    $interpreter = new KeywordInterpreter();
    foreach ($expected as $text => $operation) {
      $this->assertSame($operation, $interpreter->interpret($text, [])?->operation, $text);
    }
    $this->assertNull($interpreter->interpret("don't protect the cake", ['object-1' => 'cake']));
    $this->assertSame('object-1', $interpreter->interpret("don't crop the cake", ['object-1' => 'cake'])?->object);
  }

}
