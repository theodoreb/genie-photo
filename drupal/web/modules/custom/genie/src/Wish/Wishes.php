<?php

declare(strict_types=1);

namespace Drupal\genie\Wish;

use Drupal\genie\Game\Ledger;
use Drupal\genie\Game\Session;
use Drupal\genie\Photo\BodyPaint;
use Drupal\genie\Photo\Outlines;
use Drupal\genie\Photo\Places;

/**
 * Grants what players wish for themselves: covers, looks and paint.
 */
final class Wishes {

  private const REPLIES = [
    'cover' => 'Granted, %s. Your face is now covered.',
    'visible' => 'Granted, %s. You are back in the photo.',
    'disguise' => 'Granted, %s. Nobody will know you behind those sunglasses.',
    'gold' => 'Granted, %s. You are now a gold statue.',
    'cartoon' => 'Granted, %s. You are now a comic drawing.',
  ];

  public function __construct(
    private readonly Ledger $ledger,
    private readonly WishInterpreter $interpreter,
  ) {}

  /**
   * Turns a wish into the player's standing wish. A later wish replaces it.
   *
   * @return array{granted: bool, reply: string, revision: int}
   *   The outcome, with a reply built from what really happened.
   */
  public function grant(int $uid, string $name, string $text): array {
    $current = $this->ledger->read();
    $wish = $this->interpreter->interpret($text);
    if ($wish === NULL) {
      return $this->refusal('I did not understand. Try "hide me", "turn me to gold", "disguise me" or "make me a cartoon".');
    }
    if (!isset($current->regions["person-$uid"])) {
      return $this->refusal(Places::NO_PLACE);
    }

    $session = $this->ledger->write(static function (Session $session) use ($uid, $wish): void {
      $session->wishes[$uid] = $wish->operation->value;
    });
    return [
      'granted' => TRUE,
      'reply' => sprintf(self::REPLIES[$wish->operation->value], $name),
      'revision' => $session->revision,
    ];
  }

  /**
   * Repaints the player's whole person.
   *
   * @return array{granted: bool, reply: string, revision: int}
   *   The outcome.
   */
  public function paint(int $uid, string $name, BodyPaint $paint): array {
    $current = $this->ledger->read();
    if (!isset($current->regions["person-$uid"])) {
      return $this->refusal(Places::NO_PLACE);
    }
    if (Outlines::reach($current)["person-$uid"] === []) {
      return $this->refusal(Places::NO_REACH);
    }
    $session = $this->ledger->write(static function (Session $session) use ($uid, $paint): void {
      if ($paint->isEmpty()) {
        unset($session->paints[$uid]);
      }
      else {
        $session->paints[$uid] = $paint;
      }
    });
    return [
      'granted' => TRUE,
      'reply' => sprintf('Granted, %s. Your person is repainted.', $name),
      'revision' => $session->revision,
    ];
  }

  /**
   * Builds the outcome of a wish that changed nothing.
   *
   * @return array{granted: bool, reply: string, revision: int}
   *   The outcome.
   */
  private function refusal(string $reply): array {
    return ['granted' => FALSE, 'reply' => $reply, 'revision' => $this->ledger->read()->revision];
  }

}
