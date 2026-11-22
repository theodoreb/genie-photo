<?php

declare(strict_types=1);

namespace Drupal\Tests\genie\Unit;

use Drupal\genie\Photo\OpenAiReview;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\AI\Platform\Job\JobHandle;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Tests the OpenAI batch review against a fake OpenAI.
 */
#[CoversClass(OpenAiReview::class)]
#[Group('genie')]
final class OpenAiReviewTest extends UnitTestCase {

  /**
   * The request bodies sent, by method and URL.
   *
   * @var array<string, string>
   */
  private array $sent = [];

  /**
   * Builds a review on a fake OpenAI whose batch has a given status.
   */
  private function review(string $status, string $output = ''): OpenAiReview {
    $client = new MockHttpClient(function (string $method, string $url, array $options) use ($status, $output): MockResponse {
      $this->sent["$method $url"] = self::body($options['body'] ?? '');
      return match ("$method $url") {
        'POST https://api.openai.com/v1/files' => new MockResponse('{"id": "file-in"}'),
        'POST https://api.openai.com/v1/batches' => new MockResponse('{"id": "batch_1", "status": "validating"}'),
        'GET https://api.openai.com/v1/batches/batch_1' => new MockResponse(json_encode(
          ['id' => 'batch_1', 'status' => $status] + ($output === '' ? [] : ['output_file_id' => 'file-out'])
        )),
        'GET https://api.openai.com/v1/files/file-out/content' => new MockResponse($output),
        default => throw new \LogicException("The review sent $method $url."),
      };
    });
    return new OpenAiReview('sk-test', $client);
  }

  /**
   * Reads a request body that may arrive in chunks.
   */
  private static function body(mixed $body): string {
    if (!is_callable($body)) {
      return (string) $body;
    }
    $read = '';
    while (($chunk = $body(8192)) !== '') {
      $read .= $chunk;
    }
    return $read;
  }

  /**
   * One line of a batch's output file, as OpenAI writes it.
   */
  private static function answer(string $id, string $text): string {
    return json_encode([
      'custom_id' => $id,
      'response' => [
        'status_code' => 200,
        'body' => [
          'status' => 'completed',
          'output' => [
            [
              'type' => 'message',
              'role' => 'assistant',
              'content' => [['type' => 'output_text', 'text' => $text]],
            ],
          ],
        ],
      ],
      'error' => NULL,
    ]);
  }

  /**
   * A submit sends each picture with the question and the answer schema.
   */
  public function testSubmitSendsOneBatch(): void {
    $batch = $this->review('validating')->submit(['game-7' => 'png bytes']);
    $this->assertSame('batch_1', JobHandle::fromString($batch)->getId());
    $file = $this->sent['POST https://api.openai.com/v1/files'];
    $this->assertStringContainsString('"custom_id":"game-7"', $file);
    $this->assertStringContainsString('"url":"\/v1\/responses"', $file);
    $this->assertStringContainsString(base64_encode('png bytes'), $file, 'The picture travels in the request.');
    $this->assertStringContainsString('"name":"review"', $file, 'The answer follows the schema.');
  }

  /**
   * A batch in progress has no answers yet.
   */
  public function testRunningBatchHasNoAnswers(): void {
    $review = $this->review('in_progress');
    $this->assertNull($review->collect($review->submit(['game-7' => 'png'])));
  }

  /**
   * A finished batch gives each answer by picture, and skips an errored one.
   */
  public function testFinishedBatchGivesAnswers(): void {
    $output = implode("\n", [
      self::answer('game-7', '{"sexual": true}'),
      self::answer('game-8', '{"sexual": false}'),
      json_encode([
        'custom_id' => 'game-9',
        'response' => NULL,
        'error' => ['code' => 'invalid_request', 'message' => 'Bad image.'],
      ]),
    ]);
    $review = $this->review('completed', $output);
    $answers = $review->collect($review->submit(['game-7' => 'a', 'game-8' => 'b', 'game-9' => 'c']));
    $this->assertSame(['game-7' => TRUE, 'game-8' => FALSE], $answers);
  }

  /**
   * A batch that failed before any answer gives none.
   */
  public function testFailedBatchGivesNoAnswers(): void {
    $review = $this->review('failed');
    $this->assertSame([], $review->collect($review->submit(['game-7' => 'png'])));
  }

}
