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
  public function grant(int $uid, string $name, string $wish): array {
    $operation = $this->interpreter->interpret($wish);
    if ($operation === NULL) {
      return $this->refusal('I did not understand. Try "hide me", "crop me out" or "show me".');
    }
    if (!isset($this->ledger->read()->regions["person-$uid"])) {
      return $this->refusal('Mark your place in the photo first, on the web view.');
    }

    $session = $this->ledger->write(static function (Session $session) use ($uid, $operation): void {
      $session->wishes[$uid] = $operation->value;
    });
    $reply = sprintf(self::REPLIES[$operation->value], $name);
    if ($this->framer->frame($session) instanceof Collision) {
      $reply .= ' No frame fits every wish right now, so the photo waits.';
    }
    return ['granted' => TRUE, 'reply' => $reply, 'revision' => $session->revision];
  }

  /**
   * Describes the session for one player, without image data.
   *
   * @return array<string, mixed>
   *   The revision, the photo size, the regions and the frame.
   */
  public function view(int $uid): array {
    $session = $this->ledger->read();
    return [
      'revision' => $session->revision,
      'photo' => $session->photo,
      'is_uploader' => $session->uploader === $uid,
      'regions' => array_values($session->regions),
      'wish' => $session->wishes[$uid] ?? Operation::Show->value,
      'frame' => $session->photo === NULL ? NULL : $this->describe($this->framer->frame($session)),
    ];
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
