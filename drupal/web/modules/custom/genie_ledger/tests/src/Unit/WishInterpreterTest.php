<?php

declare(strict_types=1);

namespace Drupal\Tests\genie_ledger\Unit;

use Drupal\Component\Plugin\Factory\FactoryInterface;
use Drupal\genie_ledger\KeywordInterpreter;
use Drupal\genie_ledger\Operation;
use Drupal\genie_ledger\Wish;
use Drupal\genie_ledger\WishInterpreter;
use Drupal\Tests\UnitTestCase;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\AI\Platform\StructuredOutput\Validator\ValidatorSubscriber;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Reads wishes from scripted model answers.
 *
 * @group genie_ledger
 */
final class WishInterpreterTest extends UnitTestCase {

  /**
   * Builds an interpreter whose model gives one scripted answer.
   */
  private function interpreter(\Closure|string $answer): WishInterpreter {
    $dispatcher = new EventDispatcher();
    $dispatcher->addSubscriber(new PlatformSubscriber());
    $dispatcher->addSubscriber(new ValidatorSubscriber());
    $platforms = $this->createMock(FactoryInterface::class);
    $platforms->method('createInstance')->willReturn(
      MockPlatformFactory::createPlatform($answer, eventDispatcher: $dispatcher),
    );
    return new WishInterpreter(
      $platforms,
      $this->getConfigFactoryStub(['genie_ledger.settings' => ['text_model' => 'test']]),
      new KeywordInterpreter(),
    );
  }

  /**
   * Turns valid answers into wishes.
   */
  public function testValidAnswers(): void {
    $wish = $this->interpreter('{"target":"me","operation":"cover","object":null}')->interpret('conceal me', []);
    $this->assertEquals(new Wish(Operation::Cover, source: 'model'), $wish);

    $wish = $this->interpreter('{"target":"me","operation":"protect","object":"the Lamp"}')
      ->interpret('the lamp stays', ['object-1' => 'lamp']);
    $this->assertEquals(new Wish(Operation::Protect, 'object-1', 'model'), $wish);

    $this->assertNull($this->interpreter('{"target":"nothing","operation":null,"object":null}')->interpret('hello', []));
  }

  /**
   * Asks again after an answer it cannot trust, even when keywords would match.
   *
   * @dataProvider unusableAnswers
   */
  public function testUnusableAnswers(string $answer): void {
    $this->assertNull($this->interpreter($answer)->interpret('hide my face', []));
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
      'unknown operation' => ['{"target":"me","operation":"explode","object":null}'],
      'no target' => ['{"operation":"cover","object":null}'],
      'about someone else' => ['{"target":"someone_else","operation":"cover","object":null}'],
      'protect without object' => ['{"target":"me","operation":"protect","object":null}'],
    ];
  }

  /**
   * Falls back to keywords when no model answers.
   */
  public function testUnavailableModel(): void {
    $interpreter = $this->interpreter(static fn () => throw new RuntimeException('Connection refused.'));
    $this->assertEquals(new Wish(Operation::Cover), $interpreter->interpret('hide my face', []));
  }

}
