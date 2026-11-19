<?php

declare(strict_types=1);

namespace Drupal\genie_ledger;

/**
 * Gives every grid cell of the photo to at most one person.
 *
 * Every stored photo has a long side of 1600 pixels, so every photo has the
 * same blocks. One outline cell holds five by five fine paint cells.
 */
final class Outlines {

  public const LONG_SIDE = 1600;
  public const FINE = 8;
  public const FINE_PER_CELL = 5;
  public const CELL = self::FINE * self::FINE_PER_CELL;

  /**
   * Most runs one person's outline may hold.
   */
  private const MAX_RUNS = 600;

  /**
   * Returns the number of outline columns.
   *
   * @param array{width: int, height: int} $photo
   *   The photo size.
   */
  public static function columns(array $photo): int {
    return (int) ceil($photo['width'] / self::CELL);
  }

  /**
   * Returns the number of outline rows.
   *
   * @param array{width: int, height: int} $photo
   *   The photo size.
   */
  public static function rows(array $photo): int {
    return (int) ceil($photo['height'] / self::CELL);
  }

  /**
   * Reads an outline and keeps what lies on the person's box.
   *
   * @param mixed $runs
   *   Runs as [row, first column, last column].
   * @param array{x: int, y: int, width: int, height: int} $body
   *   The person's box. One cell around it still counts.
   * @param array{width: int, height: int} $photo
   *   The photo size.
   *
   * @return list<array{int, int, int}>
   *   The runs, cut to the box and the grid.
   *
   * @throws \InvalidArgumentException
   *   When the runs are malformed.
   */
  public static function parse(mixed $runs, array $body, array $photo): array {
    if (!is_array($runs) || !array_is_list($runs) || count($runs) > self::MAX_RUNS) {
      throw new \InvalidArgumentException(sprintf('An outline is a list of at most %d runs.', self::MAX_RUNS));
    }
    $cell = self::CELL;
    $firstColumn = max(0, (int) ceil(($body['x'] - $cell) / $cell - 0.5));
    $lastColumn = min(self::columns($photo) - 1, (int) floor(($body['x'] + $body['width'] + $cell) / $cell - 0.5));
    $firstRow = max(0, (int) ceil(($body['y'] - $cell) / $cell - 0.5));
    $lastRow = min(self::rows($photo) - 1, (int) floor(($body['y'] + $body['height'] + $cell) / $cell - 0.5));
    $kept = [];
    foreach ($runs as $run) {
      if (!is_array($run) || !array_is_list($run) || count($run) !== 3
        || !is_int($run[0]) || !is_int($run[1]) || !is_int($run[2])) {
        throw new \InvalidArgumentException('An outline run is [row, first column, last column].');
      }
      $first = max($run[1], $firstColumn);
      $last = min($run[2], $lastColumn);
      if ($run[0] >= $firstRow && $run[0] <= $lastRow && $first <= $last) {
        $kept[] = [$run[0], $first, $last];
      }
    }
    return $kept;
  }

  /**
   * Finds who owns each cell. The person nearest the front wins a shared cell.
   *
   * @param \Drupal\genie_ledger\Session $session
   *   The session, which has a photo.
   * @param bool $tracedOnly
   *   TRUE leaves out people known only by their box.
   *
   * @return array<int, string>
   *   Region or proposal id, by cell index.
   */
  public static function owners(Session $session, bool $tracedOnly = FALSE): array {
    $people = $session->proposals;
    foreach ($session->regions as $id => $region) {
      if ($region['kind'] === 'person') {
        $people[$id] = $region;
      }
    }
    uasort($people, static fn (array $one, array $other): int => ($one['rank'] ?? PHP_INT_MAX) <=> ($other['rank'] ?? PHP_INT_MAX));
    $owners = [];
    foreach ($people as $id => $person) {
      if (!$tracedOnly || !empty($person['cells'])) {
        foreach (self::cellsOf($person, $session->photo) as $cell) {
          $owners[$cell] ??= $id;
        }
      }
    }
    return $owners;
  }

  /**
   * Returns a cell's area in photo pixels.
   *
   * @param int $cell
   *   The cell index.
   * @param array{width: int, height: int} $photo
   *   The photo size.
   *
   * @return array{x: int, y: int, width: int, height: int}
   *   The area, inside the photo.
   */
  public static function area(int $cell, array $photo): array {
    $side = self::CELL;
    $x = ($cell % self::columns($photo)) * $side;
    $y = intdiv($cell, self::columns($photo)) * $side;
    return [
      'x' => $x,
      'y' => $y,
      'width' => min($side, $photo['width'] - $x),
      'height' => min($side, $photo['height'] - $y),
    ];
  }

  /**
   * Describes cells on the fine grid, as a player's assistant paints them.
   *
   * @param list<int> $cells
   *   The cell indexes.
   * @param array{width: int, height: int} $photo
   *   The photo size.
   *
   * @return list<array{int, int, int, int}>
   *   Rectangles as [first row, last row, first column, last column].
   */
  public static function editable(array $cells, array $photo): array {
    $columns = self::columns($photo);
    $rows = [];
    foreach ($cells as $cell) {
      $rows[intdiv($cell, $columns)][] = $cell % $columns;
    }
    ksort($rows);
    $rectangles = [];
    $open = [];
    foreach ($rows as $row => $cellsInRow) {
      sort($cellsInRow);
      $next = [];
      foreach (self::runs($cellsInRow) as [$first, $last]) {
        $key = "$first:$last";
        $index = isset($open[$key]) && $open[$key]['row'] === $row - 1 ? $open[$key]['index'] : count($rectangles);
        $rectangles[$index] = [
          $rectangles[$index][0] ?? $row * self::FINE_PER_CELL,
          ($row + 1) * self::FINE_PER_CELL - 1,
          $first * self::FINE_PER_CELL,
          ($last + 1) * self::FINE_PER_CELL - 1,
        ];
        $next[$key] = ['row' => $row, 'index' => $index];
      }
      $open = $next;
    }
    return $rectangles;
  }

  /**
   * Lists the cells a person stands on.
   *
   * @param array<string, mixed> $person
   *   A region or a proposal.
   * @param array{width: int, height: int} $photo
   *   The photo size.
   *
   * @return list<int>
   *   The cell indexes: the outline, or the box without one.
   */
  private static function cellsOf(array $person, array $photo): array {
    $columns = self::columns($photo);
    $side = self::CELL;
    $cells = [];
    foreach ($person['cells'] ?? [] as [$row, $first, $last]) {
      foreach (range($first, $last) as $column) {
        $cells[] = $row * $columns + $column;
      }
    }
    if ($cells !== []) {
      return $cells;
    }
    for ($row = 0; $row < self::rows($photo); $row++) {
      $y = ($row + 0.5) * $side;
      if ($y >= $person['y'] && $y < $person['y'] + $person['height']) {
        for ($column = 0; $column < $columns; $column++) {
          $x = ($column + 0.5) * $side;
          if ($x >= $person['x'] && $x < $person['x'] + $person['width']) {
            $cells[] = $row * $columns + $column;
          }
        }
      }
    }
    return $cells;
  }

  /**
   * Joins sorted numbers into runs.
   *
   * @param list<int> $numbers
   *   Sorted numbers.
   *
   * @return list<array{int, int}>
   *   Runs as [first, last].
   */
  private static function runs(array $numbers): array {
    $runs = [];
    foreach ($numbers as $number) {
      $last = array_key_last($runs);
      if ($last !== NULL && $runs[$last][1] >= $number - 1) {
        $runs[$last][1] = $number;
      }
      else {
        $runs[] = [$number, $number];
      }
    }
    return $runs;
  }

}
