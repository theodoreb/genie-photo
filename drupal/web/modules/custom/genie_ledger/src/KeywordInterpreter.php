<?php

declare(strict_types=1);

namespace Drupal\genie_ledger;

/**
 * Reads a wish from plain words, with no model.
 */
final class KeywordInterpreter {

  /**
   * Patterns by operation. The first match wins, so negated wishes come first.
   */
  private const PATTERNS = [
    [
      Operation::Show,
      '/\b(show me|put me back|bring me back|unhide|uncover|(don\'t|do not|stop) (hide|hiding|cover|covering))\b/',
    ],
    [
      Operation::Exclude,
      '/\b((crop|cut|remove|exclude) (me|myself)|(leave|keep|take) (me|myself) out|not in (the|this) (photo|picture))\b/',
    ],
    [Operation::Cover, '/\b(hide|cover|mask)\b/'],
  ];

  /**
   * Returns the operation, or NULL when the wish is unclear.
   */
  public function interpret(string $wish): ?Operation {
    $wish = mb_strtolower($wish);
    foreach (self::PATTERNS as [$operation, $pattern]) {
      if (preg_match($pattern, $wish)) {
        return $operation;
      }
    }
    return NULL;
  }

}
