<?php

declare(strict_types=1);

namespace Drupal\genie\Wish;

use Drupal\genie\Game\Ledger;
use Drupal\genie\Game\Session;
use Drupal\genie\Photo\BodyPaint;
use Drupal\genie\Photo\Outlines;
use Drupal\genie\Photo\Places;

/**
 * Grants what players wish for themselves: framing, paint and protection.
 */
final class Wishes {

  private const REPLIES = [
    'cover' => 'Granted, %s. Your face is now covered.',
    'exclude' => 'Granted, %s. The frame now leaves you out.',
    'visible' => 'Granted, %s. You are back in the photo.',
    'center_on' => 'Granted, %s. The frame now keeps you in the middle.',
    'stop_center' => 'Granted, %s. You no longer need the middle.',
    'disguise' => 'Granted, %s. Nobody will know you behind those sunglasses.',
    'gold' => 'Granted, %s. You are now a gold statue.',
    'cartoon' => 'Granted, %s. You are now a comic drawing.',
  ];

  public function __construct(
    private readonly Ledger $ledger,
    private readonly Framer $framer,
    private readonly WishInterpreter $interpreter,
  ) {}

  /**
   * Turns a wish into the player's standing wish.
   *
   * @return array{granted: bool, reply: string, revision: int}
   *   The outcome, with a reply built from what really happened.
   */
  public function grant(int $uid, string $name, string $text): array {
    $current = $this->ledger->read();
    $wish = $this->interpreter->interpret($text, $current->objectNames());
    if ($wish === NULL) {
      return $this->refusal('I did not understand. Try "hide me", "crop me out", "put me in the middle", "turn me to gold", "disguise me", "make me a cartoon" or "protect the cake".');
    }
    if ($wish->operation !== Operation::Protect && !isset($current->regions["person-$uid"])) {
      return $this->refusal(Places::NO_PLACE);
    }

    $session = $this->ledger->write(static function (Session $session) use ($uid, $name, $wish): void {
      self::apply($session, $uid, $name, $wish);
    });
    $reply = $this->reply($session, $uid, $name, $wish);
    $result = $this->framer->frame($session);
    if ($result instanceof Collision) {
      $reply .= sprintf(' No frame fits every wish right now. It depends on: %s.', implode(', ', $result->players));
    }
    return ['granted' => TRUE, 'reply' => $reply, 'revision' => $session->revision];
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
   * Lets the uploader protect an object or release it.
   *
   * @return array{revision: int}
   *   The new revision.
   *
   * @throws \InvalidArgumentException
   *   When the caller is not the uploader or the object does not exist.
   */
  public function setProtected(int $uid, string $name, string $object, bool $protected): array {
    $session = $this->ledger->write(static function (Session $session) use ($uid, $name, $object, $protected): void {
      if ($session->uploader !== $uid) {
        throw new \InvalidArgumentException('Only the uploader decides what is protected.');
      }
      if (($session->regions[$object]['kind'] ?? NULL) !== 'object') {
        throw new \InvalidArgumentException('This object does not exist.');
      }
      unset($session->suggestions[$object], $session->protected[$object]);
      if ($protected) {
        $session->protected[$object] = $name;
      }
    });
    return ['revision' => $session->revision];
  }

  /**
   * Records a wish. A later wish replaces what it contradicts.
   */
  private static function apply(Session $session, int $uid, string $name, Wish $wish): void {
    switch ($wish->operation) {
      case Operation::Center:
        $session->centered[$uid] = TRUE;
        if (($session->wishes[$uid] ?? NULL) === Operation::Exclude->value) {
          $session->wishes[$uid] = Operation::Show->value;
        }
        break;

      case Operation::StopCenter:
        unset($session->centered[$uid]);
        break;

      case Operation::Protect:
        if ($session->uploader === $uid) {
          $session->protected[$wish->object] = $name;
          unset($session->suggestions[$wish->object]);
        }
        elseif (!in_array($name, $session->suggestions[$wish->object] ?? [], TRUE)) {
          $session->suggestions[$wish->object][] = $name;
        }
        break;

      case Operation::Exclude:
        unset($session->centered[$uid]);
        $session->wishes[$uid] = $wish->operation->value;
        break;

      default:
        $session->wishes[$uid] = $wish->operation->value;
    }
  }

  /**
   * Builds the genie's reply from the recorded result.
   */
  private function reply(Session $session, int $uid, string $name, Wish $wish): string {
    if ($wish->operation !== Operation::Protect) {
      return sprintf(self::REPLIES[$wish->operation->value], $name);
    }
    $object = $session->regions[$wish->object]['label'];
    return $session->uploader === $uid
      ? sprintf('Granted, %s. The %s stays in the frame.', $name, $object)
      : sprintf('Noted, %s. The uploader decides whether the %s stays in the frame.', $name, $object);
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
