<?php

declare(strict_types=1);

namespace Drupal\genie_ledger;

/**
 * What a player's assistant paints on the player's whole person.
 */
final readonly class BodyPaint {

  public const EMPTY = '.';
  public const ORIGINAL = '*';
  public const MAX_COLORS = 16;

  /**
   * Most runs of cells one paint may send.
   */
  public const MAX_SPANS = 1024;

  /**
   * Builds a paint from checked values.
   *
   * @param bool $pixelate
   *   TRUE turns the person into pixel art first.
   * @param list<string> $palette
   *   Colors as #rrggbb.
   * @param list<array{int, int, int, string}> $spans
   *   Runs of fine cells as [row, first column, last column, cell].
   */
  private function __construct(
    public bool $pixelate,
    public array $palette,
    public array $spans,
  ) {}

  /**
   * Validates what a client sent.
   *
   * @param mixed $pixelate
   *   The pixel-art choice.
   * @param mixed $palette
   *   The palette.
   * @param mixed $spans
   *   The runs of cells.
   *
   * @throws \InvalidArgumentException
   *   When a value breaks the format.
   */
  public static function fromInput(mixed $pixelate, mixed $palette, mixed $spans): self {
    $pixelate ??= FALSE;
    $palette ??= [];
    $spans ??= [];
    if (!is_bool($pixelate)) {
      throw new \InvalidArgumentException('Pixelate is true or false.');
    }
    if (!is_array($spans) || !array_is_list($spans) || count($spans) > self::MAX_SPANS) {
      throw new \InvalidArgumentException(sprintf('Spans is a list of at most %d runs.', self::MAX_SPANS));
    }
    if (!is_array($palette) || !array_is_list($palette) || count($palette) > self::MAX_COLORS) {
      throw new \InvalidArgumentException(sprintf('A palette is a list of at most %d colors.', self::MAX_COLORS));
    }
    foreach ($palette as $color) {
      if (!is_string($color) || !preg_match('/^#[0-9a-f]{6}$/iD', $color)) {
        throw new \InvalidArgumentException('Palette colors look like #ff8800.');
      }
    }
    foreach ($spans as $span) {
      if (!is_array($span) || !array_is_list($span) || count($span) !== 4
        || !is_int($span[0]) || !is_int($span[1]) || !is_int($span[2]) || !is_string($span[3])
        || !preg_match('/^[.*0-9a-f]$/D', $span[3])) {
        throw new \InvalidArgumentException('A span is [row, first column, last column, cell].');
      }
      if (ctype_xdigit($span[3]) && hexdec($span[3]) >= count($palette)) {
        throw new \InvalidArgumentException("Cell $span[3] has no color in the palette.");
      }
    }
    return new self($pixelate, $palette, $spans);
  }

  /**
   * Tells whether this paint leaves the person as the photo shows them.
   */
  public function isEmpty(): bool {
    return !$this->pixelate && $this->spans === [];
  }

}
