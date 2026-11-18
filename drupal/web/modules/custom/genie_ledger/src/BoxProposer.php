<?php

declare(strict_types=1);

namespace Drupal\genie_ledger;

use Drupal\ai\AiPlatformPluginManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformException;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;

/**
 * Asks a vision model on this machine where the people and objects are.
 */
final class BoxProposer {

  private const LOOPBACK_HOSTS = ['127.0.0.1', 'localhost', '[::1]'];
  private const MAX_BOXES = 12;
  private const TIMEOUT_SECONDS = 300;

  /**
   * The model answers in thousandths of the photo's width and height.
   */
  private const SCALE = 1000;

  private const INSTRUCTIONS = <<<'TEXT'
    Find each person and each notable object in this group photo.
    For each one give kind ("person" or "object") and a box.
    Coordinates go from 0 to 1000, as thousandths of the image width and height.
    TEXT;

  public function __construct(
    #[Autowire(service: 'plugin.manager.ai.platform')]
    private readonly AiPlatformPluginManager $platforms,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns the proposed boxes, or NULL when no local model can answer.
   *
   * @return list<array{kind: string, x: int, y: int, width: int, height: int}>|null
   *   The boxes in photo pixels.
   */
  public function propose(string $jpeg, int $width, int $height): ?array {
    // The photo must never leave this machine, whatever the providers say.
    if (!$this->usesOnlyLoopback()) {
      return NULL;
    }
    // A vision model on a CPU takes minutes, and the HTTP client reads this
    // limit on each request.
    ini_set('default_socket_timeout', (string) self::TIMEOUT_SECONDS);
    try {
      $answer = $this->platforms->createInstance('managed:genie')->invoke(
        $this->configFactory->get('genie_ledger.settings')->get('vision_model'),
        new MessageBag(Message::forSystem(self::INSTRUCTIONS), Message::ofUser('List the boxes.', new Image($jpeg, 'image/jpeg'))),
        ['response_format' => self::responseFormat()],
      )->asText();
      $boxes = json_decode($answer, TRUE, 5, JSON_THROW_ON_ERROR)['boxes'] ?? [];
    }
    catch (PlatformException | HttpException | \JsonException) {
      return NULL;
    }
    return is_array($boxes) ? self::toPixels($boxes, $width, $height) : NULL;
  }

  /**
   * Tells whether every provider of the genie platform is on this machine.
   */
  private function usesOnlyLoopback(): bool {
    $providers = $this->configFactory->get('ai.platform.genie')->get('platform_plugin_configuration.providers');
    foreach ($providers as $provider) {
      $url = (string) $this->configFactory->get("ai.inference_provider.$provider")->get('configuration.base_url');
      if (!self::isLoopback($url)) {
        return FALSE;
      }
    }
    return $providers !== [];
  }

  /**
   * Tells whether a URL points at this machine.
   */
  public static function isLoopback(string $url): bool {
    return in_array(parse_url($url, PHP_URL_HOST), self::LOOPBACK_HOSTS, TRUE);
  }

  /**
   * Converts model output to photo pixels and drops what makes no sense.
   *
   * @param array<mixed> $boxes
   *   The boxes from the model, which are untrusted.
   * @param int $width
   *   The photo width.
   * @param int $height
   *   The photo height.
   *
   * @return list<array{kind: string, x: int, y: int, width: int, height: int}>
   *   The usable boxes.
   */
  public static function toPixels(array $boxes, int $width, int $height): array {
    $result = [];
    foreach ($boxes as $box) {
      if (!is_array($box) || !in_array($box['kind'] ?? NULL, ['person', 'object'], TRUE)) {
        continue;
      }
      foreach (['x', 'y', 'width', 'height'] as $field) {
        if (!is_int($box[$field] ?? NULL) || $box[$field] < 0) {
          continue 2;
        }
      }
      if ($box['width'] < 1 || $box['height'] < 1
        || $box['x'] + $box['width'] > self::SCALE || $box['y'] + $box['height'] > self::SCALE) {
        continue;
      }
      $result[] = [
        'kind' => $box['kind'],
        'x' => intdiv($box['x'] * $width, self::SCALE),
        'y' => intdiv($box['y'] * $height, self::SCALE),
        'width' => max(1, intdiv($box['width'] * $width, self::SCALE)),
        'height' => max(1, intdiv($box['height'] * $height, self::SCALE)),
      ];
    }
    return array_slice($result, 0, self::MAX_BOXES);
  }

  /**
   * Describes the answer the model must give.
   *
   * @return array<string, mixed>
   *   The response format.
   */
  private static function responseFormat(): array {
    $number = ['type' => 'integer'];
    return [
      'type' => 'json_schema',
      'json_schema' => [
        'name' => 'boxes',
        'strict' => TRUE,
        'schema' => [
          'type' => 'object',
          'properties' => [
            'boxes' => [
              'type' => 'array',
              'items' => [
                'type' => 'object',
                'properties' => [
                  'kind' => ['type' => 'string', 'enum' => ['person', 'object']],
                  'x' => $number,
                  'y' => $number,
                  'width' => $number,
                  'height' => $number,
                ],
                'required' => ['kind', 'x', 'y', 'width', 'height'],
                'additionalProperties' => FALSE,
              ],
            ],
          ],
          'required' => ['boxes'],
          'additionalProperties' => FALSE,
        ],
      ],
    ];
  }

}
