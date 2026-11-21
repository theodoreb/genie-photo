<?php

declare(strict_types=1);

namespace Drupal\genie\Photo;

use Symfony\AI\Platform\Bridge\OpenAi\Factory;
use Symfony\AI\Platform\Exception\JobFailedException;
use Symfony\AI\Platform\Job\JobHandle;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Asks OpenAI, through its Batch API, whether pictures show sexual content.
 *
 * A batch answers within 24 hours.
 */
final class OpenAiReview {

  private const MODEL = 'gpt-5-mini';

  private const QUESTION = 'Does this picture show sexual content: nudity, a sexual act or a sexual pose? Answer for the whole picture.';

  /**
   * The answer, as an OpenAI JSON schema.
   */
  private const ANSWER = [
    'type' => 'json_schema',
    'json_schema' => [
      'name' => 'review',
      'strict' => TRUE,
      'schema' => [
        'type' => 'object',
        'properties' => ['sexual' => ['type' => 'boolean']],
        'required' => ['sexual'],
        'additionalProperties' => FALSE,
      ],
    ],
  ];

  public function __construct(
    #[\SensitiveParameter]
    private readonly ?string $apiKey,
    private readonly HttpClientInterface $httpClient,
  ) {}

  /**
   * Builds the review from the OPENAI_API_KEY environment variable.
   */
  public static function fromEnvironment(): self {
    $key = getenv('OPENAI_API_KEY');
    return new self($key === FALSE || $key === '' ? NULL : $key, HttpClient::create());
  }

  /**
   * Tells whether an API key is set.
   */
  public function enabled(): bool {
    return $this->apiKey !== NULL;
  }

  /**
   * Starts one batch for some pictures.
   *
   * @param array<string, string> $pictures
   *   PNG bytes, by an id of at most 64 characters.
   *
   * @return string
   *   The batch, to pass to collect().
   */
  public function submit(array $pictures): string {
    $inputs = array_map(
      static fn (string $png): MessageBag => new MessageBag(Message::ofUser(self::QUESTION, new Image($png, 'image/png'))),
      $pictures,
    );
    return Factory::createPlatform($this->apiKey, $this->httpClient)
      ->invoke(self::MODEL, $inputs, ['batch' => TRUE, PlatformSubscriber::RESPONSE_FORMAT => self::ANSWER])
      ->asJob()
      ->toString();
  }

  /**
   * Reads the answers of a batch.
   *
   * @param string $batch
   *   What submit() returned.
   *
   * @return array<string, bool>|null
   *   Whether each picture shows sexual content, by its id, or NULL while the
   *   batch runs. A picture OpenAI did not answer is missing.
   */
  public function collect(string $batch): ?array {
    $jobs = Factory::createJobClient($this->apiKey, $this->httpClient);
    $job = JobHandle::fromString($batch);
    if (!$jobs->getStatus($job)->isTerminal()) {
      return NULL;
    }
    try {
      $items = $jobs->getResult($job)->getContent();
    }
    catch (JobFailedException) {
      // A batch that failed or expired before any answer has none to read.
      return [];
    }
    $answers = [];
    foreach ($items as $item) {
      if ($item->isSuccess()) {
        $answers[$item->getId()] = (bool) json_decode($item->getResult()->getContent(), TRUE, flags: JSON_THROW_ON_ERROR)['sexual'];
      }
    }
    return $answers;
  }

}
