<?php

declare(strict_types=1);

namespace Drupal\genie_ledger;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;

/**
 * Keeps the session photo in private files.
 */
final class PhotoStore {

  public const URI = 'private://genie/photo.jpg';
  private const FRAMES = 'private://genie/frames';

  public function __construct(private readonly FileSystemInterface $fileSystem) {}

  /**
   * Stores the photo.
   */
  public function save(string $jpeg): void {
    $directory = dirname(self::URI);
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $this->fileSystem->saveData($jpeg, self::URI, FileExists::Replace);
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
    $this->fileSystem->deleteRecursive(self::FRAMES);
    $directory = self::FRAMES;
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $base = self::FRAMES . "/$revision";
    $this->fileSystem->saveData($export->png, "$base.png", FileExists::Replace);
    // The hash goes last, because its presence marks a complete pair.
    $this->fileSystem->saveData($export->hash, "$base.sha256", FileExists::Replace);
  }

  /**
   * Removes the photo and its frames.
   */
  public function delete(): void {
    $this->fileSystem->delete(self::URI);
    $this->fileSystem->deleteRecursive(self::FRAMES);
  }

}
