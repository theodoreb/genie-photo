<?php

declare(strict_types=1);

namespace Drupal\genie_ledger;

/**
 * What a player's assistant paints on the player's whole person.
 */
final readonly class BodyPaint {

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
    if (!is_array($spans) || !array_is_list($spans) || count($spans) > Sticker::MAX_SPANS) {
      throw new \InvalidArgumentException(sprintf('Spans is a list of at most %d runs.', Sticker::MAX_SPANS));
    }
    Sticker::assertPalette($palette);
    foreach ($spans as $span) {
      Sticker::assertSpan($span, $palette);
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
