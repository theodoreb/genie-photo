<?php

declare(strict_types=1);

namespace Drupal\genie\Photo;

use Drupal\Component\Uuid\Php;
use Drupal\genie\Game\Session;

/**
 * Records who an assistant found in the photo, and who claims whom.
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

  /**
   * The refusal for a player who has no place yet.
   */
  public const NO_PLACE = 'Claim your place first: call photo_get, then claim_place.';

  /**
   * The refusal for a player whom people in front cover entirely.
   */
  public const NO_REACH = 'People in front of you cover your whole person.';

  private const MAX_PEOPLE = 30;
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
   * @param \Drupal\genie\Game\Session $session
   *   The session.
   * @param mixed $people
   *   List of people with description, head and body.
   * @param bool $guesses
   *   TRUE when the people are boxes guessed for a prepared game, which wait
   *   for an assistant's trace. An assistant's scan traces each person.
   *
   * @throws \InvalidArgumentException
   *   When the scan breaks a rule.
   */
  public static function scan(Session $session, mixed $people, bool $guesses = FALSE): void {
    self::assertPhoto($session);
    if (!is_array($people) || !array_is_list($people) || count($people) > self::MAX_PEOPLE) {
      throw new \InvalidArgumentException(sprintf('People is a list of at most %d entries.', self::MAX_PEOPLE));
    }
    $survey = $session->survey;
    foreach ($people as $person) {
      $head = self::head($person['head'] ?? NULL, $session);
      $body = self::bodyOf($person, $session);
      $cells = Outlines::parse($person['outline'] ?? [], $body, $session->photo);
      $label = self::label($person['description'] ?? NULL);
      // An untraced person would hold every assistant back.
      if ($cells === [] && !$guesses) {
        throw new \InvalidArgumentException(sprintf('Trace %s: give outline runs on their grid cells.', $label));
      }
      $claimed = self::claimedHead($session, $head);
      if ($claimed === NULL) {
        self::dropUnclaimedOn($session, $head);
        $survey->people[] = self::person($label, $head, $body, $cells);
        continue;
      }
      // A place claimed on a page has no outline until an assistant traces
      // it. The trace fills the cells, read inside the player's own body box.
      foreach ($survey->people as $index => $known) {
        if ($known['id'] === $claimed && $known['cells'] === []) {
          $box = ['x' => $known['x'], 'y' => $known['y'], 'width' => $known['width'], 'height' => $known['height']];
          $survey->people[$index]['cells'] = Outlines::parse($person['outline'], $box, $session->photo)
            ?: throw new \InvalidArgumentException(sprintf('The outline of %s lies outside their place.', $label));
        }
      }
    }
    $session->refresh();
  }

  /**
   * Gives a player a proposed box, or the boxes they send to correct theirs.
   *
   * @param \Drupal\genie\Game\Session $session
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
    $survey = $session->survey;
    $previous = $session->regions["person-$uid"] ?? NULL;
    if ($person !== NULL) {
      if (!is_string($person) || !isset($session->proposals[$person])) {
        throw new \InvalidArgumentException('This box is gone. Look at the photo again.');
      }
      $place = $person;
      if ($head !== NULL) {
        self::moveHead($survey, $place, self::headOn($head, $session->proposals[$person], $session));
      }
    }
    elseif ($body === NULL && $previous !== NULL) {
      $place = $previous['person'];
      self::moveHead($survey, $place, self::headOn($head, $previous, $session));
    }
    else {
      $added = self::person($previous['description'] ?? $name, self::head($head, $session), self::box($body, $session), []);
      $survey->people[] = $added;
      $place = $added['id'];
    }
    $placeHead = $survey->people[self::indexOf($survey, $place)]['head'];
    foreach ($session->regions as $region) {
      if ($region['owner'] !== $uid && self::sameHead($placeHead, $region['head'])) {
        throw new \InvalidArgumentException(sprintf('%s already holds this place.', $region['label']));
      }
    }
    // The person this player gave up goes back on offer.
    $session->claims[$uid] = $place;
    $session->players[$uid] = $name;
    self::dropUnclaimedOn($session, $placeHead);
    $session->refresh();
  }

  /**
   * Removes a person nobody claims.
   *
   * @param \Drupal\genie\Game\Session $session
   *   The session.
   * @param string $id
   *   The person's id.
   */
  public static function dismiss(Session $session, string $id): void {
    if (isset($session->proposals[$id])) {
      array_splice($session->survey->people, self::indexOf($session->survey, $id), 1);
      $session->refresh();
    }
  }

  /**
   * Lists the people the detector found, by the label the outline view shows.
   *
   * @param \Drupal\genie\Game\Session $session
   *   The session.
   *
   * @return array<string, array{x: int, y: int, width: int, height: int}>
   *   Boxes by label: P1, P2 and so on, from left to right.
   */
  public static function detected(Session $session): array {
    $detected = [];
    foreach ($session->detection['boxes'] as $index => $box) {
      $detected['P' . ($index + 1)] = $box;
    }
    return $detected;
  }

  /**
   * Finds a scanned person's body: the detector's box, or the one sent.
   *
   * @param mixed $person
   *   The person a scan reported.
   * @param \Drupal\genie\Game\Session $session
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
   * @param \Drupal\genie\Game\Session $session
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
   * Builds a person with a new id, who stands behind everyone known so far.
   *
   * @param string $description
   *   Who this is.
   * @param array{x: int, y: int, width: int, height: int} $head
   *   The head box.
   * @param array{x: int, y: int, width: int, height: int} $body
   *   The body box.
   * @param list<array{int, int, int}> $cells
   *   The outline.
   *
   * @return array<string, mixed>
   *   The person.
   */
  private static function person(string $description, array $head, array $body, array $cells): array {
    return ['id' => self::newId(), 'description' => $description, 'head' => $head, 'cells' => $cells] + $body;
  }

  /**
   * Returns a new row id.
   */
  private static function newId(): string {
    return (new Php())->generate();
  }

  /**
   * Finds a person's row.
   */
  private static function indexOf(Survey $survey, string $id): int {
    return array_search($id, array_column($survey->people, 'id'), TRUE);
  }

  /**
   * Changes a person's head box.
   *
   * @param \Drupal\genie\Photo\Survey $survey
   *   The survey.
   * @param string $id
   *   The person's id.
   * @param array{x: int, y: int, width: int, height: int} $head
   *   The head box.
   */
  private static function moveHead(Survey $survey, string $id, array $head): void {
    $survey->people[self::indexOf($survey, $id)]['head'] = $head;
  }

  /**
   * Stops a scan or a claim that comes before the photo.
   */
  private static function assertPhoto(Session $session): void {
    if ($session->survey === NULL) {
      throw new \InvalidArgumentException('The session has no photo yet.');
    }
  }

  /**
   * Gives the claimed person whose head a box stands for.
   *
   * @param \Drupal\genie\Game\Session $session
   *   The session.
   * @param array{x: int, y: int, width: int, height: int} $head
   *   The head box.
   */
  private static function claimedHead(Session $session, array $head): ?string {
    foreach ($session->regions as $region) {
      if (self::sameHead($head, $region['head'])) {
        return $region['person'];
      }
    }
    return NULL;
  }

  /**
   * Removes the unclaimed people this head box stands for.
   *
   * @param \Drupal\genie\Game\Session $session
   *   The session.
   * @param array{x: int, y: int, width: int, height: int} $head
   *   The head box.
   */
  private static function dropUnclaimedOn(Session $session, array $head): void {
    $claimed = array_flip($session->claims);
    $session->survey->people = array_values(array_filter(
      $session->survey->people,
      static fn (array $person): bool => isset($claimed[$person['id']]) || !self::sameHead($head, $person['head']),
    ));
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
   * Validates a head box, which wishes act on.
   *
   * @return array{x: int, y: int, width: int, height: int}
   *   The box.
   */
  private static function head(mixed $input, Session $session): array {
    $box = self::box($input, $session);
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
      throw new \InvalidArgumentException('Every person needs a short description.');
    }
    return mb_substr($label, 0, self::MAX_LABEL);
  }

}
