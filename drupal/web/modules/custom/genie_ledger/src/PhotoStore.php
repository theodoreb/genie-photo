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
   * Removes the photo.
   */
  public function delete(): void {
    $this->fileSystem->delete(self::URI);
  }

}
