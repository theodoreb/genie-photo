<?php

declare(strict_types=1);

namespace Drupal\genie_ledger;

use Codewithkyrian\Transformers\Pipelines\Task;
use Codewithkyrian\Transformers\Transformers;
use Codewithkyrian\Transformers\Utils\ImageDriver;
use Drupal\Core\File\FileSystemInterface;
use Symfony\AI\Platform\Bridge\TransformersPhp\Factory;

/**
 * Finds people in the photo with a local model, through Symfony AI.
 *
 * The model runs through PHP's FFI extension, which PHP allows on the command
 * line alone, so the web request starts a Drush command.
 */
final class PersonDetector {

  private const MODEL = 'Xenova/detr-resnet-50';
  private const MODELS = 'private://genie-models';
  private const MIN_SCORE = 0.5;

  /**
   * Long side of the copy the model reads, in pixels.
   */
  private const DETECTION_SIDE = 1024;

  /**
   * Smallest share of the photo a person's box may take.
   */
  private const MIN_SHARE = 0.01;

  /**
   * Overlap over union above which two boxes are one person.
   */
  private const SAME_PERSON = 0.6;

  /**
   * Share of a box another box must hold to count as wrapping it.
   */
  private const WRAPS = 0.85;

  public function __construct(private readonly FileSystemInterface $fileSystem) {}

  /**
   * Tells whether this PHP can run the model.
   */
  public function available(): bool {
    return extension_loaded('FFI');
  }

  /**
   * Starts the detection in the background.
   */
  public function launch(): void {
    $project = dirname(DRUPAL_ROOT);
    exec(sprintf(
      'cd %s && DRUPACK_NO_UPDATE_CHECK=1 nohup ./drupack drush genie:detect >/dev/null 2>&1 &',
      escapeshellarg($project),
    ));
  }

  /**
   * Finds the people in an image.
   *
   * @param string $file
   *   The path of a JPEG.
   *
   * @return list<array{x: int, y: int, width: int, height: int}>
   *   One box per person in the image's pixels, from left to right.
   */
  public function detect(string $file): array {
    $directory = self::MODELS;
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    Transformers::setup()
      ->setCacheDir($this->fileSystem->realpath($directory))
      ->setImageDriver(ImageDriver::GD)
      ->apply();
    [$width, $height] = getimagesize($file);
    $scale = self::DETECTION_SIDE / max($width, $height);
    $copy = $this->fileSystem->tempnam('temporary://', 'genie');
    imagejpeg(imagescale(imagecreatefromjpeg($file), (int) round($width * $scale), (int) round($height * $scale), IMG_BICUBIC), $this->fileSystem->realpath($copy));
    try {
      $found = Factory::createPlatform()->invoke(self::MODEL, $this->fileSystem->realpath($copy), [
        'task' => Task::ObjectDetection,
        'input_options' => ['threshold' => self::MIN_SCORE],
      ])->asObject();
    }
    finally {
      $this->fileSystem->delete($copy);
    }

    $people = [];
    foreach ($found as $object) {
      if ($object['label'] === 'person') {
        $box = $object['box'];
        $people[] = [
          'score' => $object['score'],
          'left' => max(0, (int) round($box['xmin'] / $scale)),
          'top' => max(0, (int) round($box['ymin'] / $scale)),
          'right' => min($width, (int) round($box['xmax'] / $scale)),
          'bottom' => min($height, (int) round($box['ymax'] / $scale)),
        ];
      }
    }
    return self::distinct($people, $width * $height);
  }

  /**
   * Keeps one box per person.
   *
   * @param list<array{score: float, left: int, top: int, right: int, bottom: int}> $found
   *   What the model found.
   * @param int $photoArea
   *   The photo's area.
   *
   * @return list<array{x: int, y: int, width: int, height: int}>
   *   The boxes, from left to right.
   */
  private static function distinct(array $found, int $photoArea): array {
    usort($found, static fn (array $one, array $other): int => $other['score'] <=> $one['score']);
    $kept = [];
    foreach ($found as $box) {
      $area = self::area($box);
      if ($area < $photoArea * self::MIN_SHARE) {
        continue;
      }
      $duplicate = FALSE;
      $wrapped = 0;
      foreach ($kept as $other) {
        $shared = self::shared($box, $other);
        $duplicate = $duplicate || $shared / ($area + self::area($other) - $shared) > self::SAME_PERSON;
        $wrapped += $shared >= self::area($other) * self::WRAPS ? 1 : 0;
      }
      // A box around two people already found is the group, not a person.
      if (!$duplicate && $wrapped < 2) {
        $kept[] = $box;
      }
    }
    usort($kept, static fn (array $one, array $other): int => $one['left'] <=> $other['left']);
    return array_map(static fn (array $box): array => [
      'x' => $box['left'],
      'y' => $box['top'],
      'width' => $box['right'] - $box['left'],
      'height' => $box['bottom'] - $box['top'],
    ], $kept);
  }

  /**
   * Returns the area of a box.
   *
   * @param array{left: int, top: int, right: int, bottom: int} $box
   *   The box.
   */
  private static function area(array $box): int {
    return max(0, $box['right'] - $box['left']) * max(0, $box['bottom'] - $box['top']);
  }

  /**
   * Returns the area two boxes share.
   *
   * @param array{left: int, top: int, right: int, bottom: int} $one
   *   One box.
   * @param array{left: int, top: int, right: int, bottom: int} $other
   *   The other box.
   */
  private static function shared(array $one, array $other): int {
    return self::area([
      'left' => max($one['left'], $other['left']),
      'top' => max($one['top'], $other['top']),
      'right' => min($one['right'], $other['right']),
      'bottom' => min($one['bottom'], $other['bottom']),
    ]);
  }

}
