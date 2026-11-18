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

  /**
   * Largest share of the photo one person's region may take.
   */
  private const MAX_PERSON_SHARE = 0.25;

  /**
   * Largest share of another box a new box may overlap and leave it standing.
   */
  private const MAX_OVERLAP_SHARE = 0.5;

  private const MAX_LABEL = 40;

  public function __construct(
    private readonly Ledger $ledger,
    private readonly Framer $framer,
    private readonly WishInterpreter $interpreter,
    private readonly Exports $exports,
    private readonly BoxProposer $proposer,
    private readonly PhotoStore $photos,
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
   * Places the player's sticker.
   *
   * @return array{granted: bool, reply: string, revision: int}
   *   The outcome.
   */
  public function setSticker(int $uid, string $name, Sticker $sticker): array {
    if (!isset($this->ledger->read()->regions["person-$uid"])) {
      return $this->refusal('Mark your place in the photo first, on the web view.');
    }
    $session = $this->ledger->write(static function (Session $session) use ($uid, $sticker): void {
      $session->stickers[$uid] = $sticker;
    });
    $reply = ($session->wishes[$uid] ?? NULL) === Operation::Cover->value
      ? sprintf('Noted, %s. Your cover hides the sticker until you ask to be shown.', $name)
      : sprintf('Granted, %s. Your sticker is on the photo.', $name);
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
   * Asks the local model for boxes, which people then confirm.
   *
   * @return array{available: bool, count: int, revision: int}
   *   The outcome. Without a local model nothing changes.
   *
   * @throws \InvalidArgumentException
   *   When the caller is not the uploader.
   */
  public function proposeBoxes(int $uid): array {
    $current = $this->ledger->read();
    if ($current->uploader !== $uid || $current->photo === NULL) {
      throw new \InvalidArgumentException('Only the uploader asks for proposals, once the photo is in.');
    }
    $boxes = $this->proposer->propose($this->photos->read(), $current->photo['width'], $current->photo['height']);
    if ($boxes === NULL) {
      return ['available' => FALSE, 'count' => 0, 'revision' => $current->revision];
    }
    $session = $this->ledger->write(static function (Session $session) use ($boxes): void {
      $session->proposals = [];
      foreach ($boxes as $index => $box) {
        $claimed = array_filter($session->regions, static fn (array $region): bool => self::claims($region, $box));
        if ($claimed === []) {
          $session->proposals["proposal-$index"] = $box;
        }
      }
    });
    return ['available' => TRUE, 'count' => count($session->proposals), 'revision' => $session->revision];
  }

  /**
   * Marks the caller's place, or an object for the uploader.
   *
   * @param int $uid
   *   The player.
   * @param string $name
   *   The player's name.
   * @param array<string, mixed> $input
   *   The region as a client sent it.
   *
   * @return array{revision: int}
   *   The new revision.
   *
   * @throws \InvalidArgumentException
   *   When the region breaks a rule.
   */
  public function putRegion(int $uid, string $name, array $input): array {
    $session = $this->ledger->write(static function (Session $session) use ($uid, $name, $input): void {
      $box = self::box($input, $session);
      if ($input['kind'] === 'person') {
        self::assertOwnPlace($box, $uid, $session);
        $session->players[$uid] = $name;
        $id = "person-$uid";
        $region = ['kind' => 'person', 'owner' => $uid, 'label' => $name] + $box;
      }
      else {
        if ($session->uploader !== $uid) {
          throw new \InvalidArgumentException('Only the uploader marks objects.');
        }
        $label = trim((string) ($input['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > self::MAX_LABEL) {
          throw new \InvalidArgumentException(sprintf('An object needs a name of at most %d characters.', self::MAX_LABEL));
        }
        $id = 'object-' . md5($label);
        $region = ['kind' => 'object', 'owner' => $uid, 'label' => $label] + $box;
      }
      $session->regions[$id] = $region;
      $session->proposals = array_filter(
        $session->proposals,
        static fn (array $proposal): bool => !self::claims($region, $proposal),
      );
    });
    return ['revision' => $session->revision];
  }

  /**
   * Validates a box sent by a client against the photo.
   *
   * @param array<string, mixed> $input
   *   The region as a client sent it.
   * @param \Drupal\genie_ledger\Session $session
   *   The session.
   *
   * @return array{x: int, y: int, width: int, height: int}
   *   The box.
   */
  private static function box(array $input, Session $session): array {
    if ($session->photo === NULL) {
      throw new \InvalidArgumentException('The session has no photo yet.');
    }
    if (!in_array($input['kind'] ?? NULL, ['person', 'object'], TRUE)) {
      throw new \InvalidArgumentException('A region is a person or an object.');
    }
    $box = [];
    foreach (['x', 'y', 'width', 'height'] as $field) {
      if (!is_int($input[$field] ?? NULL)) {
        throw new \InvalidArgumentException("The region needs an integer $field.");
      }
      $box[$field] = $input[$field];
    }
    if ($box['x'] < 0 || $box['y'] < 0 || $box['width'] < 1 || $box['height'] < 1
      || $box['x'] + $box['width'] > $session->photo['width']
      || $box['y'] + $box['height'] > $session->photo['height']) {
      throw new \InvalidArgumentException('The region must lie inside the photo.');
    }
    return $box;
  }

  /**
   * Stops a player from claiming the photo or another player's place.
   *
   * Cover and exclude wishes act on this box.
   *
   * @param array{x: int, y: int, width: int, height: int} $box
   *   The box.
   * @param int $uid
   *   The player marking it.
   * @param \Drupal\genie_ledger\Session $session
   *   The session.
   */
  private static function assertOwnPlace(array $box, int $uid, Session $session): void {
    if ($box['width'] * $box['height'] > $session->photo['width'] * $session->photo['height'] * self::MAX_PERSON_SHARE) {
      throw new \InvalidArgumentException('Your place can take at most a quarter of the photo.');
    }
    foreach ($session->regions as $region) {
      if ($region['kind'] === 'person' && $region['owner'] !== $uid && self::covers($box, $region)) {
        throw new \InvalidArgumentException(sprintf('This box covers %s.', $region['label']));
      }
    }
  }

  /**
   * Tells whether a marked region already stands for a proposed box.
   *
   * @param array<string, mixed> $region
   *   The marked region.
   * @param array<string, mixed> $proposal
   *   The proposed box.
   */
  private static function claims(array $region, array $proposal): bool {
    return $region['kind'] === $proposal['kind'] && self::covers($region, $proposal);
  }

  /**
   * Tells whether a box overlaps more than the allowed share of another.
   *
   * @param array{x: int, y: int, width: int, height: int} $box
   *   The box.
   * @param array{x: int, y: int, width: int, height: int} $other
   *   The box it may cover.
   */
  private static function covers(array $box, array $other): bool {
    $width = min($box['x'] + $box['width'], $other['x'] + $other['width']) - max($box['x'], $other['x']);
    $height = min($box['y'] + $box['height'], $other['y'] + $other['height']) - max($box['y'], $other['y']);
    return $width > 0 && $height > 0
      && $width * $height > $other['width'] * $other['height'] * self::MAX_OVERLAP_SHARE;
  }

  /**
   * Removes a wrong proposal.
   *
   * @return array{revision: int}
   *   The new revision.
   *
   * @throws \InvalidArgumentException
   *   When the caller is not the uploader.
   */
  public function dismissProposal(int $uid, string $proposal): array {
    $session = $this->ledger->write(static function (Session $session) use ($uid, $proposal): void {
      if ($session->uploader !== $uid) {
        throw new \InvalidArgumentException('Only the uploader dismisses proposals.');
      }
      unset($session->proposals[$proposal]);
    });
    return ['revision' => $session->revision];
  }

  /**
   * Records a player's approval of the picture with this hash.
   *
   * @return array<string, mixed>
   *   The state of the export.
   *
   * @throws \InvalidArgumentException
   *   When the player takes no part or the picture has changed.
   */
  public function approve(int $uid, string $hash): array {
    $seen = $this->ledger->read();
    if (!isset($seen->players[$uid])) {
      throw new \InvalidArgumentException('Mark your place in the photo first.');
    }
    $export = $this->exports->current($seen);
    if ($export === NULL || !hash_equals($export->hash, $hash)) {
      throw new \InvalidArgumentException('The picture has changed. Look at it again.');
    }
    $session = $this->ledger->write(static function (Session $session) use ($uid, $hash, $seen): void {
      if ($session->revision !== $seen->revision) {
        throw new \InvalidArgumentException('The picture has changed. Look at it again.');
      }
      $session->approvals[$uid] = $hash;
    }, FALSE);
    return $this->exportState($session, $uid, $export);
  }

  /**
   * Describes the session for one player, without image data.
   *
   * @return array<string, mixed>
   *   The revision, the photo size, the regions and the frame.
   */
  public function view(int $uid): array {
    $session = $this->ledger->read();
    $export = $session->photo === NULL ? NULL : $this->exports->current($session);
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
      'update' => $session->update,
      'photo' => $session->photo,
      'is_uploader' => $session->uploader === $uid,
      'regions' => $regions,
      'proposals' => array_map(
        static fn (string $id, array $box): array => $box + ['id' => $id],
        array_keys($session->proposals),
        $session->proposals,
      ),
      'wish' => $session->wishes[$uid] ?? Operation::Show->value,
      'centered' => isset($session->centered[$uid]),
      'frame' => $session->photo === NULL ? NULL : $this->describe($this->framer->frame($session)),
      'export' => $export === NULL ? NULL : $this->exportState($session, $uid, $export),
    ];
  }

  /**
   * Describes who has approved the picture.
   *
   * @return array<string, mixed>
   *   The hash, the names still awaited, and whether the picture is final.
   */
  private function exportState(Session $session, int $uid, Export $export): array {
    $waiting = array_filter(
      $session->players,
      static fn (int $player): bool => ($session->approvals[$player] ?? NULL) !== $export->hash,
      ARRAY_FILTER_USE_KEY,
    );
    return [
      'hash' => $export->hash,
      'approved_by_you' => ($session->approvals[$uid] ?? NULL) === $export->hash,
      'waiting_for' => array_values($waiting),
      'final' => $session->players !== [] && $waiting === [],
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
