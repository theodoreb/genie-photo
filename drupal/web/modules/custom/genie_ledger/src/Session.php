<?php

declare(strict_types=1);

namespace Drupal\genie_ledger;

/**
 * The one photo session of this site.
 */
final class Session {

  /**
   * Raised by every committed write.
   */
  public int $revision = 0;

  /**
   * The account that uploaded the photo.
   */
  public ?int $uploader = NULL;

  /**
   * Size of the stored photo.
   *
   * @var array{width: int, height: int}|null
   */
  public ?array $photo = NULL;

  /**
   * Marked regions in photo pixels, by region id.
   *
   * @var array<string, array{kind: string, owner: int, label: string, x: int, y: int, width: int, height: int}>
   */
  public array $regions = [];

  /**
   * The standing wish of each player, by account id.
   *
   * @var array<int, string>
   */
  public array $wishes = [];

  /**
   * Players who asked for the middle of the frame, by account id.
   *
   * @var array<int, true>
   */
  public array $centered = [];

  /**
   * Who protected each object, by region id.
   *
   * @var array<string, string>
   */
  public array $protected = [];

  /**
   * Who suggested protecting each object, by region id.
   *
   * @var array<string, list<string>>
   */
  public array $suggestions = [];

}
