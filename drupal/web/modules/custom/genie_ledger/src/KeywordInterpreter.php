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
      Operation::StopCenter,
      '/\b(stop|don\'t|do not|never|no longer|not)\b.*\b(center|centre|centering|centring|middle)\b|\bout of the (center|centre|middle)\b/',
    ],
    [Operation::Center, '/\b(center|centre|middle)\b/'],
    [
      Operation::Show,
      '/\b(show me|put me back|bring me back|unhide|uncover)\b|\b(stop|don\'t|do not|never|no longer)\b.*(\b(hide|hiding|cover|covering|mask|crop|cut|remove|exclude)\b.*\b(me|my|myself)\b|\b(leave|keep|take) (me|myself) out\b)/',
    ],
    [
      Operation::Exclude,
      '/\b((crop|cut|remove|exclude) (me|myself)|(leave|keep|take) (me|myself) out|not in (the|this) (photo|picture))\b/',
    ],
    [Operation::Cover, '/\b(hide|cover|mask)\b.*\b(me|my|myself)\b/'],
  ];

  private const PROTECT = '/\b(protect|keep|save|don\'t crop|do not crop|don\'t cut|do not cut)\b/';

  private const NOT_PROTECT = '/\b(stop|don\'t|do not|never|no longer) (protect|keep|save)\b/';

  /**
   * Returns the wish, or NULL when it is unclear.
   *
   * @param string $text
   *   The words of the player.
   * @param array<string, string> $objects
   *   Object names by region id.
   */
  public function interpret(string $text, array $objects): ?Wish {
    $text = str_replace('’', "'", mb_strtolower($text));
    foreach (self::PATTERNS as [$operation, $pattern]) {
      if (preg_match($pattern, $text)) {
        return new Wish($operation);
      }
    }
    if (preg_match(self::PROTECT, $text) && !preg_match(self::NOT_PROTECT, $text)) {
      foreach ($objects as $id => $name) {
        if (str_contains($text, mb_strtolower($name))) {
          return new Wish(Operation::Protect, $id);
        }
      }
    }
    return NULL;
  }

}
