<?php

declare(strict_types=1);

namespace Drupal\genie_ledger;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\media\MediaInterface;

/**
 * Keeps the photo and what was found in it on a media item.
 */
final class PhotoStore {

  public const URI = 'private://genie/photo.jpg';
  private const FRAMES = 'private://genie/frames';
  private const BUNDLE = 'genie_photo';
  private const IMAGE = 'field_genie_image';
  private const DETECTOR = 'field_genie_detector';
  private const BOXES = 'field_genie_boxes';
  private const PEOPLE = 'field_genie_people';
  private const OBJECTS = 'field_genie_objects';

  /**
   * Box keys, by the suffix of their sub-field.
   */
  private const BOX_KEYS = ['x' => 'x', 'y' => 'y', 'w' => 'width', 'h' => 'height'];

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly FileRepositoryInterface $files,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Stores a new photo on a new media item.
   *
   * @param string $jpeg
   *   The photo.
   * @param int $uid
   *   The player who uploaded it.
   * @param string $detector
   *   The detector's first status.
   */
  public function create(string $jpeg, int $uid, string $detector): Survey {
    $directory = dirname(self::URI);
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $file = $this->files->writeData($jpeg, self::URI, FileExists::Replace);
    // Unpublished, so only the game's own tools hand out the photo.
    $media = $this->media()->create([
      'bundle' => self::BUNDLE,
      'name' => 'Group photo',
      'uid' => $uid,
      'status' => 0,
      self::IMAGE => ['target_id' => $file->id()],
      self::DETECTOR => $detector,
    ]);
    $media->save();
    return $this->toSurvey($media);
  }

  /**
   * Returns what one media revision holds.
   */
  public function survey(int $revision): Survey {
    return $this->toSurvey($this->media()->loadRevision($revision));
  }

  /**
   * Stores a survey as a new media revision.
   *
   * @return int
   *   The new revision's id.
   */
  public function saveSurvey(Survey $survey): int {
    $media = $this->media()->loadUnchanged($survey->media);
    $media->set(self::DETECTOR, $survey->detector);
    $media->set(self::BOXES, array_map(self::boxRow(...), $survey->boxes));
    $media->set(self::PEOPLE, array_map(self::personRow(...), $survey->people));
    $media->set(self::OBJECTS, array_map(self::objectRow(...), $survey->objects));
    $media->setNewRevision(TRUE);
    $media->save();
    return (int) $media->getRevisionId();
  }

  /**
   * Returns the stored photo.
   */
  public function read(): string {
    return file_get_contents(self::URI);
  }

  /**
   * Returns the stored picture of a revision, when there is one.
   */
  public function export(int $revision): ?Export {
    $base = self::FRAMES . "/$revision";
    return file_exists("$base.sha256") ? new Export(file_get_contents("$base.png"), file_get_contents("$base.sha256")) : NULL;
  }

  /**
   * Stores a picture in place of the pictures of older revisions.
   */
  public function saveExport(int $revision, Export $export): void {
    $directory = self::FRAMES;
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $base = self::FRAMES . "/$revision";
    $this->fileSystem->saveData($export->png, "$base.png", FileExists::Replace);
    // The hash goes last, because its presence marks a complete pair.
    $this->fileSystem->saveData($export->hash, "$base.sha256", FileExists::Replace);

    foreach ($this->fileSystem->scanDirectory(self::FRAMES, '/^\d+\./') as $file) {
      if ((int) $file->name < $revision) {
        try {
          $this->fileSystem->delete($file->uri);
        }
        catch (FileException) {
          // Every open browser renders at once, and another one removed it.
        }
      }
    }
  }

  /**
   * Removes every photo item, its file and the rendered pictures.
   */
  public function delete(): void {
    $storage = $this->media();
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('bundle', self::BUNDLE)->execute();
    foreach ($storage->loadMultiple($ids) as $media) {
      $file = $media->get(self::IMAGE)->entity;
      $media->delete();
      $file?->delete();
    }
    $this->fileSystem->deleteRecursive(self::FRAMES);
  }

  /**
   * Reads a media item.
   */
  private function toSurvey(MediaInterface $media): Survey {
    $image = $media->get(self::IMAGE)->first();
    return new Survey(
      (int) $media->id(),
      (int) $media->getRevisionId(),
      (int) $image->get('width')->getValue(),
      (int) $image->get('height')->getValue(),
      $media->get(self::DETECTOR)->value,
      array_map(static fn (array $row): array => self::box($row, ''), $media->get(self::BOXES)->getValue()),
      array_map(static fn (array $row): array => [
        'id' => $row['cfi_uuid'],
        'description' => $row['description__value'],
        'head' => self::box($row, 'head_'),
        'cells' => json_decode($row['outline__value'] ?? '[]', TRUE, flags: JSON_THROW_ON_ERROR),
      ] + self::box($row, 'body_'), $media->get(self::PEOPLE)->getValue()),
      array_map(static fn (array $row): array => [
        'id' => $row['cfi_uuid'],
        'label' => $row['label__value'],
        'reporter' => (int) $row['reporter__value'],
      ] + self::box($row, ''), $media->get(self::OBJECTS)->getValue()),
    );
  }

  /**
   * Reads a box from a stored row.
   *
   * @param array<string, mixed> $row
   *   The row.
   * @param string $prefix
   *   The prefix of the box's sub-fields.
   *
   * @return array{x: int, y: int, width: int, height: int}
   *   The box.
   */
  private static function box(array $row, string $prefix): array {
    $box = [];
    foreach (self::BOX_KEYS as $suffix => $key) {
      $box[$key] = (int) $row["$prefix{$suffix}__value"];
    }
    return $box;
  }

  /**
   * Writes a box as sub-field values.
   *
   * @param array{x: int, y: int, width: int, height: int} $box
   *   The box.
   * @param string $prefix
   *   The prefix of the box's sub-fields.
   *
   * @return array<string, int>
   *   The values.
   */
  private static function boxRow(array $box, string $prefix = ''): array {
    $row = [];
    foreach (self::BOX_KEYS as $suffix => $key) {
      $row["$prefix$suffix"] = $box[$key];
    }
    return $row;
  }

  /**
   * Writes a person as sub-field values.
   *
   * @param array<string, mixed> $person
   *   The person.
   *
   * @return array<string, mixed>
   *   The values.
   */
  private static function personRow(array $person): array {
    return [
      'cfi_uuid' => $person['id'],
      'description' => $person['description'],
      'outline' => $person['cells'] === [] ? NULL : json_encode($person['cells'], JSON_THROW_ON_ERROR),
    ] + self::boxRow($person, 'body_') + self::boxRow($person['head'], 'head_');
  }

  /**
   * Writes an object as sub-field values.
   *
   * @param array<string, mixed> $object
   *   The object.
   *
   * @return array<string, mixed>
   *   The values.
   */
  private static function objectRow(array $object): array {
    return [
      'cfi_uuid' => $object['id'],
      'label' => $object['label'],
      'reporter' => $object['reporter'],
    ] + self::boxRow($object);
  }

  /**
   * Returns the media storage.
   */
  private function media(): RevisionableStorageInterface {
    return $this->entityTypeManager->getStorage('media');
  }

}
