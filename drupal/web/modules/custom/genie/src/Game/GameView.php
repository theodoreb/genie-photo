<?php

declare(strict_types=1);

namespace Drupal\genie\Game;

use Drupal\Core\Url;
use Drupal\genie\Photo\Archive;
use Drupal\genie\Wish\Collision;
use Drupal\genie\Wish\Frame;
use Drupal\genie\Wish\Framer;
use Drupal\genie\Wish\Operation;

/**
 * Describes a game without image data.
 *
 * A player gets their own view, for session_get and the game resource. The
 * public pages get the view anyone may read.
 */
final class GameView {

  /**
   * What the web view may read about a place.
   */
  private const PLACE_KEYS = ['kind', 'owner', 'label', 'description', 'head', 'x', 'y', 'width', 'height'];

  public function __construct(
    private readonly Ledger $ledger,
    private readonly Exports $exports,
    private readonly Framer $framer,
    private readonly Archive $archive,
  ) {}

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
      $regions[] = self::place($region) + [
        'id' => $id,
        'protected' => isset($session->protected[$id]),
        'suggested_by' => $session->suggestions[$id] ?? [],
      ];
    }
    $game = $this->ledger->game();
    return [
      'state' => $game->get('field_genie_state')->value,
      'topic' => RevisionPublisher::TOPIC . $game->uuid(),
      'invite' => Url::fromRoute('genie.join', ['code' => $game->get('field_genie_invite')->value])->setAbsolute()->toString(),
      'revision' => $session->revision,
      'update' => $session->update,
      'photo' => $session->photo,
      'is_uploader' => $session->uploader === $uid,
      'regions' => $regions,
      'proposals' => array_map(
        static fn (string $id, array $box): array => self::place($box) + ['id' => $id],
        array_keys($session->proposals),
        $session->proposals,
      ),
      'wish' => $session->wishes[$uid] ?? Operation::Show->value,
      'centered' => isset($session->centered[$uid]),
      'frame' => $session->photo === NULL ? NULL : self::describe($this->framer->frame($session)),
      'export' => $export === NULL ? NULL : $session->receipt($uid, $export->hash),
    ];
  }

  /**
   * Describes the current game for anyone, without image data.
   *
   * It leaves out the wishes, the boxes and the approvals.
   *
   * @return array<string, mixed>
   *   The state, the players, the people left to claim and the photo's source.
   */
  public function publicView(): array {
    $session = $this->ledger->read();
    $game = $this->ledger->game();
    $state = $game->get('field_genie_state')->value;
    $archive = $game->get('field_genie_archive')->value;
    // Before an assistant traces the people, the detector's count stands in.
    $free = $session->survey->people === [] ? count($session->detection['boxes']) : count($session->proposals);
    $joinable = $archive !== NULL && $state === 'open' && $free > 0 && count($session->seated) < Games::SEATS;
    return [
      'id' => $game->uuid(),
      'state' => $state,
      'revision' => $session->revision,
      'photo' => $session->photo,
      'players' => array_values(array_intersect_key($session->players, $session->claims)),
      'free' => $free,
      'has_picture' => $this->framer->frame($session) instanceof Frame,
      'join' => $joinable
        ? Url::fromRoute('genie.play', ['code' => $game->get('field_genie_invite')->value])->setAbsolute()->toString()
        : NULL,
      'credit' => $archive === NULL ? NULL : $this->archive->credit($archive),
    ];
  }

  /**
   * Keeps what the web view may read about a region or a proposal.
   *
   * @param array<string, mixed> $place
   *   The region or proposal.
   *
   * @return array<string, mixed>
   *   Its public values.
   */
  private static function place(array $place): array {
    return array_intersect_key($place, array_flip(self::PLACE_KEYS));
  }

  /**
   * Describes a framing result.
   *
   * @return array<string, mixed>
   *   The crop, or the players whose wishes collide.
   */
  private static function describe(Frame|Collision $result): array {
    return $result instanceof Frame
      ? ['ready' => TRUE, 'x' => $result->x, 'y' => $result->y, 'width' => $result->width, 'height' => $result->height]
      : ['ready' => FALSE, 'players' => $result->players];
  }

}
