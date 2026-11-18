<?php

declare(strict_types=1);

namespace Drupal\genie_ledger;

/**
 * Records who and what an assistant found in the photo, and who claims whom.
 *
 * Boxes come from assistants as [x, y, width, height] in photo pixels.
 */
final class Places {

  /**
   * How tools describe a box to assistants.
   */
  public const BOX_SCHEMA = [
    'type' => 'array',
    'items' => ['type' => 'integer'],
    'minItems' => 4,
    'maxItems' => 4,
    'description' => '[x, y, width, height] in photo pixels.',
  ];

  private const MAX_PEOPLE = 30;
  private const MAX_OBJECTS = 20;
  private const MAX_LABEL = 40;

  /**
   * Largest share of the photo one head may take.
   */
  private const MAX_HEAD_SHARE = 0.25;

  /**
   * Largest share of a person's box their head may take.
   */
  private const MAX_HEAD_OF_BODY = 0.6;

  /**
   * Share of a head another box must overlap to count as the same head.
   */
  private const SAME_HEAD_SHARE = 0.5;

  /**
   * Adds what a scan found. Boxes people already claimed stay as they are.
   *
   * @param \Drupal\genie_ledger\Session $session
   *   The session.
   * @param int $uid
   *   The player whose assistant scanned.
   * @param mixed $people
   *   List of people with description, head and body.
   * @param mixed $objects
   *   List of objects with label and box.
   *
   * @throws \InvalidArgumentException
   *   When the scan breaks a rule.
   */
  public static function scan(Session $session, int $uid, mixed $people, mixed $objects): void {
    self::assertPhoto($session);
    if (!is_array($people) || !array_is_list($people) || count($people) > self::MAX_PEOPLE) {
      throw new \InvalidArgumentException(sprintf('People is a list of at most %d entries.', self::MAX_PEOPLE));
    }
    if (!is_array($objects) || !array_is_list($objects) || count($objects) > self::MAX_OBJECTS) {
      throw new \InvalidArgumentException(sprintf('Objects is a list of at most %d entries.', self::MAX_OBJECTS));
    }
    $rank = self::nextRank($session);
    foreach ($people as $person) {
      $head = self::head($person['head'] ?? NULL, $session);
      $body = self::bodyOf($person, $session);
      $cells = Outlines::parse($person['outline'] ?? [], $body, $session->photo);
      $label = self::label($person['description'] ?? NULL);
      if (self::claimedHead($session, $head) === NULL) {
        self::dropProposalsOn($session, $head);
        $session->proposals['proposal-' . substr(md5(serialize($head)), 0, 8)] = [
          'label' => $label,
          'head' => $head,
          'cells' => $cells,
          'rank' => $rank,
        ] + $body;
      }
      $rank++;
    }
    foreach ($objects as $object) {
      $label = self::label($object['label'] ?? NULL);
      $id = 'object-' . md5($label);
      // Protection depends on this box, so only the uploader moves a known one.
      if (!isset($session->regions[$id]) || $session->uploader === $uid) {
        $session->regions[$id] = ['kind' => 'object', 'owner' => $uid, 'label' => $label]
          + self::box($object['box'] ?? NULL, $session);
      }
    }
  }

  /**
   * Gives a player a proposed box, or the boxes they send to correct theirs.
   *
   * @param \Drupal\genie_ledger\Session $session
   *   The session.
   * @param int $uid
   *   The player.
   * @param string $name
   *   The player's name.
   * @param mixed $person
   *   The id of a proposed box, or NULL to correct the player's own place.
   * @param mixed $head
   *   The player's head box, which replaces the one on file.
   * @param mixed $body
   *   The body box when no id is given. Without it the place keeps its body.
   *
   * @throws \InvalidArgumentException
   *   When the box is gone or stands for another player.
   */
  public static function claim(Session $session, int $uid, string $name, mixed $person, mixed $head, mixed $body): void {
    self::assertPhoto($session);
    $id = "person-$uid";
    $previous = $session->regions[$id] ?? NULL;
    $samePerson = FALSE;
    if ($person !== NULL) {
      if (!is_string($person) || !isset($session->proposals[$person])) {
        throw new \InvalidArgumentException('This box is gone. Look at the photo again.');
      }
      $proposal = $session->proposals[$person];
      unset($session->proposals[$person]);
      $description = $proposal['label'];
      unset($proposal['label']);
      $place = $proposal;
      if ($head !== NULL) {
        $place['head'] = self::headOn($head, $place, $session);
      }
    }
    elseif ($body === NULL && $previous !== NULL) {
      $samePerson = TRUE;
      $description = $previous['description'];
      $place = [
        'head' => self::headOn($head, $previous, $session),
        'cells' => $previous['cells'] ?? [],
        'rank' => $previous['rank'] ?? self::nextRank($session),
        'x' => $previous['x'],
        'y' => $previous['y'],
        'width' => $previous['width'],
        'height' => $previous['height'],
      ];
    }
    else {
      $place = ['head' => self::head($head, $session), 'cells' => [], 'rank' => self::nextRank($session)] + self::box($body, $session);
      $description = $previous['description'] ?? $name;
    }
    // The person this player gave up goes back on offer, covered.
    if ($previous !== NULL && !$samePerson && !self::sameHead($previous['head'], $place['head'])) {
      $session->proposals['proposal-' . substr(md5(serialize($previous['head'])), 0, 8)] = [
        'label' => $previous['description'],
        'head' => $previous['head'],
        'cells' => $previous['cells'] ?? [],
        'rank' => $previous['rank'] ?? self::nextRank($session),
        'x' => $previous['x'],
        'y' => $previous['y'],
        'width' => $previous['width'],
        'height' => $previous['height'],
      ];
    }
    unset($session->regions[$id]);
    $holder = self::claimedHead($session, $place['head']);
    if ($holder !== NULL) {
      throw new \InvalidArgumentException(sprintf('%s already holds this place.', $holder));
    }
    self::dropProposalsOn($session, $place['head']);
    $session->players[$uid] = $name;
    $session->regions[$id] = ['kind' => 'person', 'owner' => $uid, 'label' => $name, 'description' => $description] + $place;
  }

  /**
   * Lists the people the detector found, by the label the outline view shows.
   *
   * @param \Drupal\genie_ledger\Session $session
   *   The session.
   *
   * @return array<string, array{x: int, y: int, width: int, height: int}>
   *   Boxes by label: P1, P2 and so on, from left to right.
   */
  public static function detected(Session $session): array {
    $detected = [];
    foreach ($session->detection['boxes'] ?? [] as $index => $box) {
      $detected['P' . ($index + 1)] = $box;
    }
    return $detected;
  }

  /**
   * Finds a scanned person's body: the detector's box, or the one sent.
   *
   * @param mixed $person
   *   The person a scan reported.
   * @param \Drupal\genie_ledger\Session $session
   *   The session.
   *
   * @return array{x: int, y: int, width: int, height: int}
   *   The box.
   */
  private static function bodyOf(mixed $person, Session $session): array {
    $label = $person['detected'] ?? NULL;
    if ($label === NULL) {
      return self::box($person['body'] ?? NULL, $session);
    }
    $detected = self::detected($session);
    if (!is_string($label) || !isset($detected[$label])) {
      throw new \InvalidArgumentException('The detector found no such person. Use a label from the outline view.');
    }
    return $detected[$label];
  }

  /**
   * Validates a head box against the person it belongs to.
   *
   * @param mixed $input
   *   The head box.
   * @param array{x: int, y: int, width: int, height: int} $body
   *   The person's box.
   * @param \Drupal\genie_ledger\Session $session
   *   The session.
   *
   * @return array{x: int, y: int, width: int, height: int}
   *   The head box.
   */
  private static function headOn(mixed $input, array $body, Session $session): array {
    $head = self::head($input, $session);
    $x = $head['x'] + $head['width'] / 2;
    $y = $head['y'] + $head['height'] / 2;
    if ($x < $body['x'] || $x > $body['x'] + $body['width'] || $y < $body['y'] || $y > $body['y'] + $body['height']) {
      throw new \InvalidArgumentException('This head is not on your person. Look at the photo again.');
    }
    if ($head['width'] * $head['height'] > $body['width'] * $body['height'] * self::MAX_HEAD_OF_BODY) {
      throw new \InvalidArgumentException('This head box is too large for your person. Draw it around the head alone.');
    }
    return $head;
  }

  /**
   * Returns the rank behind everyone known so far.
   */
  private static function nextRank(Session $session): int {
    $ranks = array_column([...$session->proposals, ...array_values($session->regions)], 'rank');
    return $ranks === [] ? 0 : max($ranks) + 1;
  }

  /**
   * Stops a scan or a claim that comes before the photo.
   */
  private static function assertPhoto(Session $session): void {
    if ($session->photo === NULL) {
      throw new \InvalidArgumentException('The session has no photo yet.');
    }
  }

  /**
   * Names the player whose head a box stands for.
   *
   * @param \Drupal\genie_ledger\Session $session
   *   The session.
   * @param array{x: int, y: int, width: int, height: int} $head
   *   The head box.
   */
  private static function claimedHead(Session $session, array $head): ?string {
    foreach ($session->regions as $region) {
      if ($region['kind'] === 'person' && (self::sameHead($head, $region['head']))) {
        return $region['label'];
      }
    }
    return NULL;
  }

  /**
   * Removes proposals for the head this box stands for.
   *
   * @param \Drupal\genie_ledger\Session $session
   *   The session.
   * @param array{x: int, y: int, width: int, height: int} $head
   *   The head box.
   */
  private static function dropProposalsOn(Session $session, array $head): void {
    $session->proposals = array_filter(
      $session->proposals,
      static fn (array $proposal): bool => !self::sameHead($head, $proposal['head']),
    );
  }

  /**
   * Tells whether two boxes overlap enough to be the same head.
   *
   * @param array{x: int, y: int, width: int, height: int} $one
   *   One box.
   * @param array{x: int, y: int, width: int, height: int} $other
   *   The other box.
   */
  private static function sameHead(array $one, array $other): bool {
    $width = min($one['x'] + $one['width'], $other['x'] + $other['width']) - max($one['x'], $other['x']);
    $height = min($one['y'] + $one['height'], $other['y'] + $other['height']) - max($one['y'], $other['y']);
    $smaller = min($one['width'] * $one['height'], $other['width'] * $other['height']);
    return $width > 0 && $height > 0 && $width * $height > $smaller * self::SAME_HEAD_SHARE;
  }

  /**
   * Validates a head box, which wishes and stickers act on.
   *
   * @return array{x: int, y: int, width: int, height: int}
   *   The box.
   */
  private static function head(mixed $input, Session $session): array {
    $box = self::box($input, $session);
    // A sticker fills the square around the head, so the longer side counts.
    if (max($box['width'], $box['height']) ** 2 > $session->photo['width'] * $session->photo['height'] * self::MAX_HEAD_SHARE) {
      throw new \InvalidArgumentException('A head can take at most a quarter of the photo.');
    }
    return $box;
  }

  /**
   * Reads a box and trims it to the photo, since models overshoot the edges.
   *
   * @return array{x: int, y: int, width: int, height: int}
   *   The box.
   */
  private static function box(mixed $input, Session $session): array {
    if (!is_array($input) || !array_is_list($input) || count($input) !== 4
      || array_filter($input, static fn (mixed $value): bool => !is_int($value) && !is_float($value)) !== []) {
      throw new \InvalidArgumentException('A box is [x, y, width, height] in photo pixels.');
    }
    [$x, $y, $width, $height] = array_map(static fn (int|float $value): int => (int) round($value), $input);
    $left = max(0, $x);
    $top = max(0, $y);
    $right = min($session->photo['width'], $x + $width);
    $bottom = min($session->photo['height'], $y + $height);
    if ($right <= $left || $bottom <= $top) {
      throw new \InvalidArgumentException('The box must overlap the photo.');
    }
    return ['x' => $left, 'y' => $top, 'width' => $right - $left, 'height' => $bottom - $top];
  }

  /**
   * Validates a short description.
   */
  private static function label(mixed $input): string {
    $label = is_string($input) ? trim($input) : '';
    if ($label === '') {
      throw new \InvalidArgumentException('Every person and object needs a short description.');
    }
    return mb_substr($label, 0, self::MAX_LABEL);
  }

}
