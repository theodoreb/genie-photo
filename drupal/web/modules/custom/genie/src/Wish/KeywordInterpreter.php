<?php

declare(strict_types=1);

namespace Drupal\genie\Wish;

/**
 * Reads a wish from plain words, with no model.
 */
final class KeywordInterpreter {

  /**
   * A negation, reaching only to the end of its clause.
   */
  private const NOT = '\b(?:stop|don\'t|do not|never|no longer)\b[a-z\' ]*';

  /**
   * Patterns by operation, where NULL marks a wish with no clear meaning.
   *
   * Negated phrases come first, because each match is removed from the text.
   */
  private const PATTERNS = [
    // Removal runs first, so its look words are gone before the look rows.
    [
      Operation::Show,
      '/' . self::NOT . '\b(?:gold|golden|statue)\b|\b(?:take|get|remove)\b[a-z\' ]*\b(?:gold|golden|statue)\b[a-z\' ]*\b(?:off|away|from me)\b|\bno more (?:gold|golden|statue)\b/',
    ],
    // The speaker comes first, so "protect the gold cup" stays a protect wish.
    [Operation::Gold, '/\b(?:me|myself) (?:[a-z]+ ){0,3}(?:gold|golden|statue)\b|\bgild me\b/'],
    [Operation::Cover, '/' . self::NOT . '\b(?:show|reveal)\b[a-z\' ]*\b(?:me|my|myself)\b/'],
    [NULL, '/' . self::NOT . '\b(?:put|bring) me back\b/'],
    [
      Operation::Show,
      '/' . self::NOT . '(?:\b(?:hide|hiding|cover|covering|mask|crop|cut|remove|exclude)\b[a-z\' ]*\b(?:me|my|myself)\b|\b(?:leave|keep|take) (?:me|myself) out\b)/',
    ],
    [
      Operation::StopCenter,
      '/\b(?:stop|don\'t|do not|never|no longer|not)\b[a-z\' ]*\b(?:center|centre|centering|centring|middle)\b|\bout of the (?:center|centre|middle)\b/',
    ],
    [Operation::Center, '/\b(?:put me back (?:in|at|to) (?:the )?)?(?:center|centre|middle)\b/'],
    [Operation::Show, '/\b(?:show me|put me back|bring me back|unhide|uncover)\b/'],
    [
      Operation::Exclude,
      '/\b(?:(?:crop|cut|remove|exclude) (?:me|myself)|(?:leave|keep|take) (?:me|myself) out|not in (?:the|this) (?:photo|picture))\b/',
    ],
    [Operation::Cover, '/\b(?:hide|cover|mask)\b[a-z\' ]*\b(?:me|my|myself)\b/'],
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
    $rest = $text;
    $found = [];
    foreach (self::PATTERNS as [$operation, $pattern]) {
      $rest = preg_replace($pattern, ' ', $rest, -1, $matches);
      if ($matches > 0) {
        $found[$operation->value ?? 'unclear'] = $operation;
      }
    }
    if ($found !== []) {
      // Two different wishes in one sentence get the rephrase reply.
      $operation = count($found) === 1 ? reset($found) : NULL;
      return $operation === NULL ? NULL : new Wish($operation);
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
