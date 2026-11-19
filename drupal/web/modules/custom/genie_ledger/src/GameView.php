<?php

declare(strict_types=1);

namespace Drupal\genie_ledger;

use Drupal\Core\Url;

/**
 * Describes a game to one player, for session_get and the game resource.
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
      'invite' => Url::fromRoute('genie_ledger.join', ['code' => $game->get('field_genie_invite')->value])->setAbsolute()->toString(),
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
      ? ['ready' => TRUE, 'width' => $result->width, 'height' => $result->height]
      : ['ready' => FALSE, 'players' => $result->players];
  }

}
