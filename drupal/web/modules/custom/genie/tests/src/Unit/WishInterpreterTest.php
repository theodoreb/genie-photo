<?php

declare(strict_types=1);

namespace Drupal\Tests\genie\Unit;

use Drupal\Component\Plugin\Factory\FactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\genie\Wish\KeywordInterpreter;
use Drupal\genie\Wish\Operation;
use Drupal\genie\Wish\Wish;
use Drupal\genie\Wish\WishInterpreter;
use Drupal\Tests\UnitTestCase;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\AI\Platform\StructuredOutput\Validator\ValidatorSubscriber;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Reads wishes from scripted model answers.
 *
 * @group genie
 */
final class WishInterpreterTest extends UnitTestCase {

  /**
   * Builds an interpreter whose model gives one scripted answer.
   */
  private function interpreter(\Closure|string $answer, bool $withinBudget = TRUE): WishInterpreter {
    $dispatcher = new EventDispatcher();
    $dispatcher->addSubscriber(new PlatformSubscriber());
    $dispatcher->addSubscriber(new ValidatorSubscriber());
    $platforms = $this->createMock(FactoryInterface::class);
    $platforms->method('createInstance')->willReturn(
      MockPlatformFactory::createPlatform($answer, eventDispatcher: $dispatcher),
    );
    $flood = $this->createMock(FloodInterface::class);
    $flood->method('isAllowed')->willReturn($withinBudget);
    return new WishInterpreter(
      $platforms,
      $this->getConfigFactoryStub(['genie.settings' => ['text_model' => 'test']]),
      new KeywordInterpreter(),
      $flood,
    );
  }

  /**
   * Turns valid answers into wishes.
   */
  public function testValidAnswers(): void {
    $wish = $this->interpreter('{"target":"me","operation":"cover"}')->interpret('conceal me');
    $this->assertEquals(new Wish(Operation::Cover, source: 'model'), $wish);

    $wish = $this->interpreter('{"target":"me","operation":"gold"}')->interpret('make me shine');
    $this->assertEquals(new Wish(Operation::Gold, source: 'model'), $wish);

    $wish = $this->interpreter('{"target":"me","operation":"disguise"}')->interpret('incognito please');
    $this->assertEquals(new Wish(Operation::Disguise, source: 'model'), $wish);

    $wish = $this->interpreter('{"target":"me","operation":"cartoon"}')->interpret('draw me');
    $this->assertEquals(new Wish(Operation::Cartoon, source: 'model'), $wish);

    $wish = $this->interpreter('{"target":"me","operation":"blur"}')->interpret('make me unrecognizable');
    $this->assertEquals(new Wish(Operation::Blur, source: 'model'), $wish);

    $this->assertNull($this->interpreter('{"target":"nothing","operation":null}')->interpret('hello'));
  }

  /**
   * Asks again after an answer it cannot trust, even when keywords would match.
   *
   * @dataProvider unusableAnswers
   */
  public function testUnusableAnswers(string $answer): void {
    $this->assertNull($this->interpreter($answer)->interpret('hide my face'));
  }

  /**
   * Answers that are malformed or contradict themselves.
   *
   * @return array<string, array{string}>
   *   The scripted answers.
   */
  public static function unusableAnswers(): array {
    return [
      'not JSON' => ['I would hide you'],
      'unknown operation' => ['{"target":"me","operation":"explode"}'],
      'no target' => ['{"operation":"cover"}'],
      'about someone else' => ['{"target":"someone_else","operation":"cover"}'],
    ];
  }

  /**
   * Falls back to keywords when no model answers.
   */
  public function testUnavailableModel(): void {
    $interpreter = $this->interpreter(static fn () => throw new RuntimeException('Connection refused.'));
    $this->assertEquals(new Wish(Operation::Cover), $interpreter->interpret('hide my face'));
  }

  /**
   * Leaves the model alone once the site spent its budget.
   */
  public function testSpentBudget(): void {
    $interpreter = $this->interpreter(static fn () => throw new \LogicException('The model was called.'), FALSE);
    $this->assertEquals(new Wish(Operation::Cover), $interpreter->interpret('hide my face'));
  }

}
