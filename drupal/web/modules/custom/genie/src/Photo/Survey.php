<?php

declare(strict_types=1);

namespace Drupal\genie\Photo;

/**
 * What one media revision says about the photo and who is in it.
 */
final class Survey {

  /**
   * Builds a survey.
   *
   * @param int $media
   *   The media item's id.
   * @param int $revision
   *   The media revision this was read from or saved as.
   * @param int $width
   *   The photo's width.
   * @param int $height
   *   The photo's height.
   * @param string $detector
   *   The detector's status: off, pending, ready or failed.
   * @param list<array{x: int, y: int, width: int, height: int}> $boxes
   *   Detected people. The first box is P1.
   * @param list<array{id: string, description: string, x: int, y: int, width: int, height: int, head: array{x: int, y: int, width: int, height: int}, cells: list<array{int, int, int}>}> $people
   *   People found in the photo, the one nearest the front first.
   * @param list<array{id: string, label: string, reporter: int, x: int, y: int, width: int, height: int}> $objects
   *   Objects found in the photo.
   */
  public function __construct(
    public int $media,
    public int $revision,
    public int $width,
    public int $height,
    public string $detector,
    public array $boxes = [],
    public array $people = [],
    public array $objects = [],
  ) {}

}
