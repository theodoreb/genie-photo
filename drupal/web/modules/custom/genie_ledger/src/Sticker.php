<?php

declare(strict_types=1);

namespace Drupal\genie_ledger;

/**
 * A 32 by 32 pixel-art sticker drawn by a player's assistant.
 */
final readonly class Sticker {

  public const SIZE = 32;
  public const EMPTY = '.';
  public const ORIGINAL = '*';
  public const MAX_COLORS = 16;
  /**
   * Longest row or row list accepted before it is cut to size.
   */
  private const MAX_INPUT = 64;

  /**
   * Most runs of cells one sticker may send.
   */
  private const MAX_SPANS = 1024;

  public const MAX_BLUR = 8;

  /**
   * Builds a sticker from checked values.
   *
   * @param list<string> $rows
   *   One string per row: ".", "*" or a hex digit naming a palette color.
   * @param list<string> $palette
   *   Colors as #rrggbb.
   * @param int $blur
   *   Blur passes over all non-empty cells.
   */
  private function __construct(
    public array $rows,
    public array $palette,
    public int $blur,
  ) {}

  /**
   * Validates what a client sent and fits it to the grid.
   *
   * Assistants miscount long rows, so short input is padded with empty cells
   * and extra cells are cut. Spans name cells directly and need no counting.
   *
   * @param mixed $rows
   *   The rows, or NULL when only spans are sent.
   * @param mixed $palette
   *   The palette.
   * @param mixed $blur
   *   The blur value.
   * @param mixed $spans
   *   Runs of cells as [row, first column, last column, cell], or NULL.
   *
   * @throws \InvalidArgumentException
   *   When a value breaks the sticker format.
   */
  public static function fromInput(mixed $rows, mixed $palette, mixed $blur, mixed $spans = NULL): self {
    $rows ??= [];
    $spans ??= [];
    if (!is_array($rows) || !array_is_list($rows) || count($rows) > self::MAX_INPUT) {
      throw new \InvalidArgumentException('A sticker is a list of 32 rows.');
    }
    if (!is_array($spans) || !array_is_list($spans) || count($spans) > self::MAX_SPANS) {
      throw new \InvalidArgumentException(sprintf('Spans is a list of at most %d runs.', self::MAX_SPANS));
    }
    if (!is_array($palette) || !array_is_list($palette) || count($palette) > self::MAX_COLORS) {
      throw new \InvalidArgumentException('A palette is a list of at most 16 colors.');
    }
    if (!is_int($blur) || $blur < 0 || $blur > self::MAX_BLUR) {
      throw new \InvalidArgumentException('Blur goes from 0 to 8.');
    }
    foreach ($palette as $color) {
      if (!is_string($color) || !preg_match('/^#[0-9a-f]{6}$/iD', $color)) {
        throw new \InvalidArgumentException('Palette colors look like #ff8800.');
      }
    }
    $grid = array_fill(0, self::SIZE, str_repeat(self::EMPTY, self::SIZE));
    foreach ($rows as $index => $row) {
      if (!is_string($row) || !preg_match('/^[.*0-9a-f]{0,' . self::MAX_INPUT . '}$/D', $row)) {
        throw new \InvalidArgumentException('Rows hold ".", "*" or a palette digit from 0 to f.');
      }
      foreach (str_split($row) as $cell) {
        self::assertCell($cell, $palette);
      }
      if ($index < self::SIZE) {
        $grid[$index] = str_pad(substr($row, 0, self::SIZE), self::SIZE, self::EMPTY);
      }
    }
    foreach ($spans as $span) {
      if (!is_array($span) || !array_is_list($span) || count($span) !== 4
        || !is_int($span[0]) || !is_int($span[1]) || !is_int($span[2]) || !is_string($span[3])
        || !preg_match('/^[.*0-9a-f]$/D', $span[3])) {
        throw new \InvalidArgumentException('A span is [row, first column, last column, cell].');
      }
      [$row, $first, $last, $cell] = $span;
      self::assertCell($cell, $palette);
      $first = max(0, $first);
      $last = min(self::SIZE - 1, $last);
      if ($row >= 0 && $row < self::SIZE && $first <= $last) {
        $grid[$row] = substr_replace($grid[$row], str_repeat($cell, $last - $first + 1), $first, $last - $first + 1);
      }
    }
    return new self($grid, $palette, $blur);
  }

  /**
   * Checks that a color cell has its palette entry.
   *
   * @param string $cell
   *   The cell.
   * @param list<mixed> $palette
   *   The palette.
   */
  private static function assertCell(string $cell, array $palette): void {
    if (ctype_xdigit($cell) && hexdec($cell) >= count($palette)) {
      throw new \InvalidArgumentException("Cell $cell has no color in the palette.");
    }
  }

}
