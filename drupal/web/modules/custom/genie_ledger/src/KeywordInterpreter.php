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
    [Operation::StopCenter, '/\b(stop|don\'t|do not) (center|centre|centering|centring)\b/'],
    [
      Operation::Exclude,
      '/\b((crop|cut|remove|exclude) (me|myself)|(leave|keep|take) (me|myself) out|not in (the|this) (photo|picture))\b/',
    ],
    [Operation::Center, '/\b(center|centre|middle)\b/'],
    [Operation::Cover, '/\b(hide|cover|mask)\b.*\b(me|my|myself)\b/'],
  ];

  private const PROTECT = '/\b(protect|keep|save|don\'t crop|do not crop|don\'t cut|do not cut)\b/';

  /**
   * Returns the wish, or NULL when it is unclear.
   *
   * @param string $text
   *   The words of the player.
   * @param array<string, string> $objects
   *   Object names by region id.
   */
  public function interpret(string $text, array $objects): ?Wish {
    $text = mb_strtolower($text);
    foreach (self::PATTERNS as [$operation, $pattern]) {
      if (preg_match($pattern, $text)) {
        return new Wish($operation);
      }
    }
    if (preg_match(self::PROTECT, $text)) {
      foreach ($objects as $id => $name) {
        if (str_contains($text, mb_strtolower($name))) {
          return new Wish(Operation::Protect, $id);
        }
      }
    }
    return NULL;
  }

}
