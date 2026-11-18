<?php

declare(strict_types=1);

namespace Drupal\genie_ledger;

/**
 * The single entry point for what players ask of the genie.
 */
final class WishDesk {

  private const REPLIES = [
    'cover' => 'Granted, %s. Your place in the photo is now covered.',
    'exclude' => 'Granted, %s. The frame now leaves you out.',
    'visible' => 'Granted, %s. You are back in the photo.',
    'center_on' => 'Granted, %s. The frame now keeps you in the middle.',
    'stop_center' => 'Granted, %s. You no longer need the middle.',
  ];

  public function __construct(
    private readonly Ledger $ledger,
    private readonly Framer $framer,
    private readonly KeywordInterpreter $interpreter,
  ) {}

  /**
   * Turns a wish into the player's standing wish.
   *
   * @return array{granted: bool, reply: string, revision: int}
   *   The outcome, with a reply built from what really happened.
   */
  public function grant(int $uid, string $name, string $text): array {
    $current = $this->ledger->read();
    $wish = $this->interpreter->interpret($text, $this->objectNames($current));
    if ($wish === NULL) {
      return $this->refusal('I did not understand. Try "hide me", "crop me out", "put me in the middle" or "protect the cake".');
    }
    if ($wish->operation !== Operation::Protect && !isset($current->regions["person-$uid"])) {
      return $this->refusal('Mark your place in the photo first, on the web view.');
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
   * Describes the session for one player, without image data.
   *
   * @return array<string, mixed>
   *   The revision, the photo size, the regions and the frame.
   */
  public function view(int $uid): array {
    $session = $this->ledger->read();
    $regions = [];
    foreach ($session->regions as $id => $region) {
      $regions[] = $region + [
        'id' => $id,
        'protected' => isset($session->protected[$id]),
        'suggested_by' => $session->suggestions[$id] ?? [],
      ];
    }
    return [
      'revision' => $session->revision,
      'photo' => $session->photo,
      'is_uploader' => $session->uploader === $uid,
      'regions' => $regions,
      'wish' => $session->wishes[$uid] ?? Operation::Show->value,
      'centered' => isset($session->centered[$uid]),
      'frame' => $session->photo === NULL ? NULL : $this->describe($this->framer->frame($session)),
    ];
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
   * Lists object names by region id.
   *
   * @return array<string, string>
   *   The names.
   */
  private function objectNames(Session $session): array {
    $names = [];
    foreach ($session->regions as $id => $region) {
      if ($region['kind'] === 'object') {
        $names[$id] = $region['label'];
      }
    }
    return $names;
  }

  /**
   * Describes a framing result.
   *
   * @return array<string, mixed>
   *   The crop, or the players whose wishes collide.
   */
  private function describe(Frame|Collision $result): array {
    return $result instanceof Frame
      ? ['ready' => TRUE, 'width' => $result->width, 'height' => $result->height]
      : ['ready' => FALSE, 'players' => $result->players];
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
