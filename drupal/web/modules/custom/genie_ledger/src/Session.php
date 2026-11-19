<?php

declare(strict_types=1);

namespace Drupal\genie_ledger;

use Drupal\node\NodeInterface;

/**
 * The photo session of one game.
 */
final class Session {

  /**
   * The properties the game node keeps. The others come from the survey.
   */
  private const STORED = [
    'revision',
    'surveyRevision',
    'paints',
    'claims',
    'wishes',
    'centered',
    'protected',
    'suggestions',
    'players',
    'update',
    'approvals',
  ];

  /**
   * Raised by every committed write.
   */
  public int $revision = 0;

  /**
   * The media revision that holds what this session knows about the photo.
   */
  public ?int $surveyRevision = NULL;

  /**
   * The account that started the game and uploads its photo: the node owner.
   */
  public ?int $uploader = NULL;

  /**
   * The workflow transition the change applies to the game, if any.
   */
  public ?string $transition = NULL;

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
   * Reads the session stored on a game node.
   */
  public static function fromGame(NodeInterface $game): self {
    $session = new self();
    $stored = $game->get('field_genie_session')->value;
    if ($stored !== NULL) {
      $data = json_decode($stored, TRUE, flags: JSON_THROW_ON_ERROR);
      foreach (self::STORED as $name) {
        $session->$name = $data[$name];
      }
      $session->paints = array_map(
        static fn (array $paint): BodyPaint => BodyPaint::fromInput($paint['pixelate'], $paint['palette'], $paint['spans']),
        $session->paints,
      );
    }
    $session->uploader = (int) $game->getOwnerId();
    return $session;
  }

  /**
   * Writes the stored properties as JSON, for the game node.
   */
  public function toJson(): string {
    $data = [];
    foreach (self::STORED as $name) {
      $data[$name] = $this->$name;
    }
    return json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
  }

}
