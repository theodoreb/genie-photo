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
   * Who stands for whom: a person id from the survey, by account id.
   *
   * @var array<int, string>
   */
  public array $claims = [];

  /**
   * Claimed people and found objects in photo pixels, from the survey.
   *
   * A person's box is the body. The head box inside it takes covers. Cells
   * hold the traced outline, and rank counts from the front.
   *
   * @var array<string, array{kind: string, owner: int, label: string, x: int, y: int, width: int, height: int, description?: string, head?: array{x: int, y: int, width: int, height: int}, cells?: list<array{int, int, int}>, rank?: int, person?: string}>
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
   * People nobody claims, by person id, from the survey.
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
    $this->regions = [];
    $this->proposals = [];
    $owners = array_flip($this->claims);
    foreach ($this->survey->people as $rank => $person) {
      ['id' => $id, 'description' => $description] = $person;
      $place = ['rank' => $rank, 'head' => $person['head'], 'cells' => $person['cells']] + self::box($person);
      if (isset($owners[$id])) {
        $uid = $owners[$id];
        $this->regions["person-$uid"] = [
          'kind' => 'person',
          'owner' => $uid,
          'label' => $this->players[$uid],
          'description' => $description,
          'person' => $id,
        ] + $place;
      }
      else {
        $this->proposals[$id] = ['label' => $description] + $place;
      }
    }
    foreach ($this->survey->objects as $object) {
      $this->regions[$object['id']] = [
        'kind' => 'object',
        'owner' => $object['reporter'],
        'label' => $object['label'],
      ] + self::box($object);
    }
  }

  /**
   * Copies a box.
   *
   * @param array<string, mixed> $item
   *   A person or an object.
   *
   * @return array{x: int, y: int, width: int, height: int}
   *   Its box.
   */
  private static function box(array $item): array {
    return ['x' => $item['x'], 'y' => $item['y'], 'width' => $item['width'], 'height' => $item['height']];
  }

  /**
   * Lists what the key-value store keeps.
   *
   * @return array<string, mixed>
   *   The stored properties.
   */
  public function __serialize(): array {
    return [
      'revision' => $this->revision,
      'surveyRevision' => $this->surveyRevision,
      'uploader' => $this->uploader,
      'paints' => $this->paints,
      'claims' => $this->claims,
      'wishes' => $this->wishes,
      'centered' => $this->centered,
      'protected' => $this->protected,
      'suggestions' => $this->suggestions,
      'players' => $this->players,
      'update' => $this->update,
      'approvals' => $this->approvals,
    ];
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
