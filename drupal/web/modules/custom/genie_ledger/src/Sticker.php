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
   * and extra cells are cut.
   *
   * @param mixed $rows
   *   The rows.
   * @param mixed $palette
   *   The palette.
   * @param mixed $blur
   *   The blur value.
   *
   * @throws \InvalidArgumentException
   *   When a value breaks the sticker format.
   */
  public static function fromInput(mixed $rows, mixed $palette, mixed $blur): self {
    if (!is_array($rows) || !array_is_list($rows) || count($rows) > self::MAX_INPUT) {
      throw new \InvalidArgumentException('A sticker is a list of 32 rows.');
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
        if (ctype_xdigit($cell) && hexdec($cell) >= count($palette)) {
          throw new \InvalidArgumentException("Cell $cell has no color in the palette.");
        }
      }
      if ($index < self::SIZE) {
        $grid[$index] = str_pad(substr($row, 0, self::SIZE), self::SIZE, self::EMPTY);
      }
    }
    return new self($grid, $palette, $blur);
  }

}
