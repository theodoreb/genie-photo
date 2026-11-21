<?php

declare(strict_types=1);

namespace Drupal\genie\Photo;

use Codewithkyrian\Transformers\Pipelines\Task;
use Drupal\Core\File\FileSystemInterface;
use Symfony\AI\Platform\Bridge\TransformersPhp\Factory;

/**
 * Scores pictures for sexual content with a local model, through Symfony AI.
 */
final class NsfwClassifier {

  /**
   * A ViT classifier with the labels "nsfw" and "sfw", under Apache-2.0.
   */
  private const MODEL = 'AdamCodd/vit-base-nsfw-detector';

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly LocalModels $models,
  ) {}

  /**
   * Tells whether this PHP can run the model.
   */
  public function available(): bool {
    return $this->models->available();
  }

  /**
   * Scores pictures through `drush genie:classify-files`, in one model run.
   *
   * @param array<string, string> $pictures
   *   Image bytes, by a key of the caller's choice.
   *
   * @return array<string, float>|null
   *   The NSFW score of each picture by the same key, or NULL when the run
   *   failed.
   */
  public function scoreInCommand(array $pictures): ?array {
    $files = array_map(fn (): string => $this->fileSystem->tempnam('temporary://', 'genie'), $pictures);
    try {
      $paths = [];
      foreach ($pictures as $key => $bytes) {
        $paths[$key] = $this->fileSystem->realpath($files[$key]);
        file_put_contents($paths[$key], $bytes);
      }
      $scores = $this->models->runInCommand('genie:classify-files', array_values($paths));
      return $scores === NULL ? NULL : array_combine(array_keys($pictures), $scores);
    }
    finally {
      array_map($this->fileSystem->delete(...), $files);
    }
  }

  /**
   * Scores image files in one model run.
   *
   * @param list<string> $files
   *   Paths of the images.
   *
   * @return list<float>
   *   The NSFW score of each file, from 0 to 1, in the same order.
   */
  public function score(array $files): array {
    $this->models->setUp();
    $labels = Factory::createPlatform()->invoke(self::MODEL, $files, [
      'task' => Task::ImageClassification,
      'input_options' => ['topK' => 2],
    ])->asObject();
    return array_map(
      static fn (array $found): float => (float) array_column($found, 'score', 'label')['nsfw'],
      $labels,
    );
  }

}
