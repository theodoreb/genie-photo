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
   * The media revision that holds what this session knows about the photo.
   */
  public ?int $surveyRevision = NULL;

  /**
   * The account that uploaded the photo.
   */
  public ?int $uploader = NULL;

  /**
   * What the pinned media revision holds. It is never stored here.
   */
  public ?Survey $survey = NULL;

  /**
   * Size of the stored photo, from the survey.
   *
   * @var array{width: int, height: int}|null
   */
  public ?array $photo = NULL;

  /**
   * The local person detector's work on this photo, from the survey.
   *
   * @var array{status: string, boxes: list<array{x: int, y: int, width: int, height: int}>}|null
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

  /**
   * Points the session at a media revision and shows what it holds.
   */
  public function pin(Survey $survey): void {
    $this->surveyRevision = $survey->revision;
    $this->survey = $survey;
    $this->refresh();
  }

  /**
   * Rebuilds the values that come from the survey.
   */
  public function refresh(): void {
    $this->photo = ['width' => $this->survey->width, 'height' => $this->survey->height];
    $this->detection = ['status' => $this->survey->detector, 'boxes' => $this->survey->boxes];
  }

  /**
   * Lists what the key-value store keeps.
   *
   * @return array<string, mixed>
   *   The stored properties.
   */
  public function __serialize(): array {
    return array_diff_key(get_object_vars($this), ['survey' => TRUE, 'photo' => TRUE, 'detection' => TRUE]);
  }

  /**
   * Restores the stored properties.
   *
   * @param array<string, mixed> $data
   *   The stored properties.
   */
  public function __unserialize(array $data): void {
    foreach ($data as $name => $value) {
      $this->$name = $value;
    }
  }

}
