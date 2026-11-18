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
   * Returns the rendered frame of a revision, when one is stored.
   */
  public function frame(int $revision): ?string {
    $uri = self::FRAMES . "/$revision.png";
    return file_exists($uri) ? file_get_contents($uri) : NULL;
  }

  /**
   * Stores a rendered frame in place of the frames of older revisions.
   */
  public function saveFrame(int $revision, string $png): void {
    $this->fileSystem->deleteRecursive(self::FRAMES);
    $directory = self::FRAMES;
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $this->fileSystem->saveData($png, self::FRAMES . "/$revision.png", FileExists::Replace);
  }

  /**
   * Removes the photo and its frames.
   */
  public function delete(): void {
    $this->fileSystem->delete(self::URI);
    $this->fileSystem->deleteRecursive(self::FRAMES);
  }

}
