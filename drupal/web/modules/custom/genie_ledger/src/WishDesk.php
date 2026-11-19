<?php

declare(strict_types=1);

namespace Drupal\genie_ledger;

/**
 * The single entry point for what players ask of the genie.
 */
final class WishDesk {

  private const REPLIES = [
    'cover' => 'Granted, %s. Your face is now covered.',
    'exclude' => 'Granted, %s. The frame now leaves you out.',
    'visible' => 'Granted, %s. You are back in the photo.',
    'center_on' => 'Granted, %s. The frame now keeps you in the middle.',
    'stop_center' => 'Granted, %s. You no longer need the middle.',
  ];

  private const NO_PLACE = 'Claim your place first: call photo_get, then claim_place.';

  private const TRACING = 'For each detected label, list the grid cells whose centre lies on that person: hair, hat, face, clothes, arms, hands and what they wear. Leave out the background, held objects, furniture and other people. Stay inside or close to the box. Where people overlap, give the cell to the one in front. Send them to scan_photo from front to back, each with its label as "detected", its cells as "outline" runs [row, first column, last column] and a head box in photo pixels. Leave out a box that holds no real person.';

  public function __construct(
    private readonly Ledger $ledger,
    private readonly Framer $framer,
    private readonly WishInterpreter $interpreter,
    private readonly Exports $exports,
    private readonly PhotoStore $photos,
    private readonly Darkroom $darkroom,
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
      return $this->refusal(self::NO_PLACE);
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
      return $this->refusal(self::NO_PLACE);
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
   * Repaints the player's whole person.
   *
   * @return array{granted: bool, reply: string, revision: int}
   *   The outcome.
   */
  public function paint(int $uid, string $name, BodyPaint $paint): array {
    if (!isset($this->ledger->read()->regions["person-$uid"])) {
      return $this->refusal(self::NO_PLACE);
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
   * Records what an assistant found in the photo.
   *
   * @return array{revision: int, unclaimed_people: int, objects: int, next: string}
   *   The outcome.
   *
   * @throws \InvalidArgumentException
   *   When the scan breaks a rule.
   */
  public function scan(int $uid, mixed $people, mixed $objects): array {
    $session = $this->ledger->write(static function (Session $session) use ($uid, $people, $objects): void {
      Places::scan($session, $uid, $people, $objects);
    });
    return [
      'revision' => $session->revision,
      'unclaimed_people' => count($session->proposals),
      'objects' => count($this->objectNames($session)),
      'next' => self::next($session, $uid),
    ];
  }

  /**
   * Gives the caller a place in the photo.
   *
   * @return array{revision: int, reply: string, next: string}
   *   The outcome.
   *
   * @throws \InvalidArgumentException
   *   When the place cannot be theirs.
   */
  public function claim(int $uid, string $name, mixed $person, mixed $head, mixed $body): array {
    $session = $this->ledger->write(static function (Session $session) use ($uid, $name, $person, $head, $body): void {
      Places::claim($session, $uid, $name, $person, $head, $body);
    });
    return [
      'revision' => $session->revision,
      'reply' => sprintf('Granted, %s. This place in the photo is yours.', $name),
      'next' => self::next($session, $uid),
    ];
  }

  /**
   * Returns an image for an assistant, with what it needs to read it.
   *
   * @return array{bytes: string, mime: string, info: array<string, mixed>}
   *   The JPEG and its description.
   *
   * @throws \InvalidArgumentException
   *   When that image does not exist yet.
   */
  public function image(int $uid, string $view): array {
    $session = $this->ledger->read();
    if ($session->photo === NULL) {
      throw new \InvalidArgumentException('There is no photo yet. Someone uploads it on the web view.');
    }
    $info = ['view' => $view, 'revision' => $session->revision, 'next' => self::next($session, $uid)];
    $bytes = match ($view) {
      'photo' => $this->photos->read(),
      'picture' => $this->pictureJpeg($session),
      'outline' => $this->darkroom->outlineView($this->photos->read(), $session),
      'my_head' => $this->headJpeg($session, $uid),
      'my_body' => $this->bodyJpeg($session, $uid),
      default => throw new \InvalidArgumentException('The view is photo, picture, outline, my_head or my_body.'),
    };
    if ($view === 'photo') {
      $info += $session->photo + [
        'people' => self::people($session),
        'objects' => array_values($this->objectNames($session)),
      ];
    }
    if ($view === 'outline') {
      $info += [
        'columns' => Outlines::columns($session->photo),
        'rows' => Outlines::rows($session->photo),
        'cell_pixels' => Outlines::CELL,
        'detector' => $session->detection['status'] ?? 'off',
        'detected' => array_map(self::boxList(...), Places::detected($session)),
        'how_to' => self::TRACING,
      ];
    }
    if ($view === 'my_body') {
      $info['grid'] = 'Numbers name fine rows and columns of the whole photo. Dimmed cells are out of your reach.';
      $info['editable'] = Outlines::editable(Outlines::reach($session)["person-$uid"], $session->photo);
      $info['on_person'] = Outlines::editable(array_keys(Outlines::owners($session), "person-$uid", TRUE), $session->photo);
    }
    if ($view === 'my_head') {
      $info['grid'] = 'Numbers name sticker columns and rows from 0 to 31. Yellow lines mark every fourth cell.';
    }
    return ['bytes' => $bytes, 'mime' => 'image/jpeg', 'info' => $info];
  }

  /**
   * Renders the shared picture for an assistant.
   */
  private function pictureJpeg(Session $session): string {
    $export = $this->exports->current($session);
    if ($export === NULL) {
      throw new \InvalidArgumentException('No frame fits every wish right now.');
    }
    return $this->darkroom->toJpeg($export->png);
  }

  /**
   * Renders a player's head under the sticker grid.
   */
  private function headJpeg(Session $session, int $uid): string {
    if (!isset($session->regions["person-$uid"])) {
      throw new \InvalidArgumentException(self::NO_PLACE);
    }
    return $this->darkroom->headView($this->photos->read(), $session, $uid);
  }

  /**
   * Renders a player's whole person under the fine grid.
   */
  private function bodyJpeg(Session $session, int $uid): string {
    if (!isset($session->regions["person-$uid"])) {
      throw new \InvalidArgumentException(self::NO_PLACE);
    }
    if (Outlines::reach($session)["person-$uid"] === []) {
      throw new \InvalidArgumentException('People in front of you cover your whole person.');
    }
    return $this->darkroom->bodyView($this->photos->read(), $session, $uid);
  }

  /**
   * Writes a box as an assistant reads it.
   *
   * @param array{x: int, y: int, width: int, height: int} $box
   *   The box.
   *
   * @return array{int, int, int, int}
   *   The box as [x, y, width, height].
   */
  private static function boxList(array $box): array {
    return [$box['x'], $box['y'], $box['width'], $box['height']];
  }

  /**
   * Lists everyone found in the photo, claimed or not.
   *
   * @return list<array<string, mixed>>
   *   The people, with their boxes as [x, y, width, height].
   */
  private static function people(Session $session): array {
    $list = self::boxList(...);
    $people = [];
    foreach ($session->proposals as $id => $proposal) {
      $people[] = [
        'id' => $id,
        'description' => $proposal['label'],
        'head' => $list($proposal['head']),
        'body' => $list($proposal),
      ];
    }
    foreach ($session->regions as $region) {
      if ($region['kind'] === 'person') {
        $people[] = [
          'id' => $region['person'],
          'player' => $region['label'],
          'description' => $region['description'],
          'head' => $list($region['head']),
          'body' => $list($region),
        ];
      }
    }
    return $people;
  }

  /**
   * Tells an assistant what to do next.
   */
  private static function next(Session $session, int $uid): string {
    if (isset($session->regions["person-$uid"])) {
      return 'Your place is claimed. Make a wish, call photo_get with view my_head before drawing a sticker, or with view my_body before paint_me.';
    }
    if ($session->proposals !== []) {
      return 'Ask your person which one they are. Look at the photo with view photo, then call claim_place with that id and a tight head box.';
    }
    return match ($session->detection['status'] ?? NULL) {
      'pending' => 'The person detector is still running. Call wait_for_update, then photo_get with view outline.',
      'ready' => 'Call photo_get with view outline, trace each numbered person, then call scan_photo.',
      default => 'Nobody has been found yet. Look at the photo with view photo, then call scan_photo.',
    };
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
      Places::dismiss($session, $proposal);
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
      throw new \InvalidArgumentException(self::NO_PLACE);
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
      unset($region['cells'], $region['rank'], $region['person']);
      $regions[] = $region + [
        'id' => $id,
        'protected' => isset($session->protected[$id]),
        'suggested_by' => $session->suggestions[$id] ?? [],
        'has_sticker' => isset($session->stickers[$region['owner']]) && $region['kind'] === 'person',
      ];
    }
    return [
      'revision' => $session->revision,
      'update' => $session->update,
      'photo' => $session->photo,
      'is_uploader' => $session->uploader === $uid,
      'regions' => $regions,
      'proposals' => array_map(
        static fn (string $id, array $box): array => array_diff_key($box, ['cells' => TRUE, 'rank' => TRUE]) + ['id' => $id],
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
