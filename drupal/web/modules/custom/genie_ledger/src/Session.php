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
   * The local person detector's work on this photo, when this PHP can run it.
   *
   * @var array{status: string, photo: string, boxes: list<array{x: int, y: int, width: int, height: int}>}|null
   */
  public ?array $detection = NULL;

  /**
   * What each player's assistant painted on their whole person, by account id.
   *
   * @var array<int, \Drupal\genie_ledger\BodyPaint>
   */
  public array $paints = [];

  /**
   * Claimed people and found objects in photo pixels, by region id.
   *
   * A person's box is the body. The head box inside it takes covers and
   * stickers. Cells hold the traced outline, and rank counts from the front.
   *
   * @var array<string, array{kind: string, owner: int, label: string, x: int, y: int, width: int, height: int, description?: string, head?: array{x: int, y: int, width: int, height: int}, cells?: list<array{int, int, int}>, rank?: int}>
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

  /**
   * The sticker of each player, by account id.
   *
   * @var array<int, \Drupal\genie_ledger\Sticker>
   */
  public array $stickers = [];

  /**
   * Who takes part: the uploader and everyone with a place, by account id.
   *
   * @var array<int, string>
   */
  public array $players = [];

  /**
   * Counts every change, including approvals, which keep the revision.
   */
  public int $update = 0;

  /**
   * People a scan found and nobody claimed yet, by id.
   *
   * @var array<string, array{label: string, x: int, y: int, width: int, height: int, head: array{x: int, y: int, width: int, height: int}, cells?: list<array{int, int, int}>, rank?: int}>
   */
  public array $proposals = [];

  /**
   * The picture hash each player approved at this revision, by account id.
   *
   * @var array<int, string>
   */
  public array $approvals = [];

}
