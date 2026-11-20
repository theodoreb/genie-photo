<?php

declare(strict_types=1);

namespace Drupal\genie\Game;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

/**
 * Starts and joins games, and abandons idle ones.
 *
 * A player is an account named `NAME@NID`, which references its game node.
 * Nothing is deleted: a game that ends keeps its photo, players and picture.
 */
final class Games {

  /**
   * Most players one game takes.
   */
  public const SEATS = 12;

  /**
   * Seconds without a change after which a game is abandoned.
   */
  public const IDLE = 86400;

  /**
   * Letters and digits of an invite code, without the ones read alike.
   */
  private const ALPHABET = 'abcdefghijkmnpqrstuvwxyz23456789';

  private const INVITE_LENGTH = 10;

  /**
   * Most finished games the public list shows.
   */
  private const LISTED_OVER = 12;

  /**
   * Exception codes of a refused seat, by the name the web view shows.
   */
  public const REFUSED = ['over' => 1, 'full' => 2, 'name' => 3, 'taken' => 4];

  public function __construct(
    private readonly Ledger $ledger,
    private readonly CurrentGame $game,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Starts a game hosted by a new player, who uploads its photo.
   */
  public function start(string $name): UserInterface {
    $node = $this->create([]);
    $host = $this->seat((int) $node->id(), $name);
    $node->setOwner($host)->save();
    return $host;
  }

  /**
   * Creates the game of a prepared photo, which nobody hosts.
   *
   * @param string $archive
   *   The id of the photo in the archive manifest.
   *
   * @return int
   *   The game's node id.
   */
  public function prepare(string $archive): int {
    return (int) $this->create(['field_genie_archive' => $archive, 'uid' => 0])->id();
  }

  /**
   * Finds the game an invite code opens, NULL for an unknown code.
   */
  public function byInvite(string $code): ?NodeInterface {
    return $this->one(['field_genie_invite' => $code]);
  }

  /**
   * Finds a game by its UUID, NULL for an unknown one.
   */
  public function byUuid(string $uuid): ?NodeInterface {
    return $this->one(['uuid' => $uuid]);
  }

  /**
   * Lists the games that have a photo: the open ones, then the latest over.
   *
   * @return list<int>
   *   Node ids, each group from the latest change.
   */
  public function listed(): array {
    $games = fn (): QueryInterface => $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'genie_game')
      ->sort('changed', 'DESC');
    $open = $games()->condition('field_genie_state', 'open')->execute();
    // A game abandoned before its photo arrived has nothing to show.
    $over = $games()
      ->condition('field_genie_state', Ledger::OVER, 'IN')
      ->exists('field_genie_photo')
      ->range(0, self::LISTED_OVER)
      ->execute();
    return array_map(intval(...), [...array_values($open), ...array_values($over)]);
  }

  /**
   * Counts the open games of prepared photos.
   */
  public function openPrepared(): int {
    return (int) $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'genie_game')
      ->condition('field_genie_state', 'open')
      ->exists('field_genie_archive')
      ->count()
      ->execute();
  }

  /**
   * Gives a new player a seat in a game.
   *
   * @throws \InvalidArgumentException
   *   The game is over or full, or the name is unusable or taken.
   */
  public function join(int $game, string $name): UserInterface {
    $this->game->enter($game);
    return $this->ledger->locked(function (NodeInterface $node) use ($game, $name): UserInterface {
      if (in_array($node->get('field_genie_state')->value, Ledger::OVER, TRUE)) {
        throw new \InvalidArgumentException('This game is over.', self::REFUSED['over']);
      }
      if ($this->players($game) >= self::SEATS) {
        throw new \InvalidArgumentException(sprintf('This game is full: it takes %d players.', self::SEATS), self::REFUSED['full']);
      }
      return $this->seat($game, $name);
    });
  }

  /**
   * Abandons every game in start or open that saw no change for a day.
   *
   * @return int
   *   How many games were abandoned.
   */
  public function abandonIdle(): int {
    $idle = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'genie_game')
      ->condition('field_genie_state', Ledger::OVER, 'NOT IN')
      ->condition('changed', $this->time->getRequestTime() - self::IDLE, '<')
      ->execute();
    $abandoned = 0;
    foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($idle) as $game => $stored) {
      // A prepared game waits for its first player as long as it takes.
      if (!$stored->get('field_genie_archive')->isEmpty() && $this->players((int) $game) === 0) {
        continue;
      }
      $this->game->enter((int) $game);
      $this->ledger->locked(static function (NodeInterface $node): void {
        /** @var \Drupal\state_machine\Plugin\Field\FieldType\StateItemInterface $state */
        $state = $node->get('field_genie_state')->first();
        $state->applyTransitionById('abandon');
        $node->save();
      });
      $abandoned++;
    }
    return $abandoned;
  }

  /**
   * Checks a player's chosen name.
   *
   * @return string
   *   The name, trimmed.
   *
   * @throws \InvalidArgumentException
   *   The name is empty, too long, or has other characters.
   */
  public static function checkName(string $name): string {
    $name = trim($name);
    // Drupal account names take these characters, and the game id follows an @.
    if (!preg_match("/^[\\p{L}\\p{N}][\\p{L}\\p{N} ._'-]{0,29}$/u", $name)) {
      throw new \InvalidArgumentException('A name has 1 to 30 letters, digits, spaces, dots, dashes or apostrophes.', self::REFUSED['name']);
    }
    return $name;
  }

  /**
   * Reads the name a player chose from their account name.
   */
  public static function displayName(string $accountName): string {
    return preg_replace('/@\d+$/', '', $accountName);
  }

  /**
   * Creates a game node with a new invite code.
   *
   * @param array<string, mixed> $values
   *   Field values on top of the ones every game gets.
   */
  private function create(array $values): NodeInterface {
    $invite = '';
    for ($i = 0; $i < self::INVITE_LENGTH; $i++) {
      $invite .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
    }
    /** @var \Drupal\node\NodeInterface $node */
    $node = $this->entityTypeManager->getStorage('node')->create($values + [
      'type' => 'genie_game',
      'title' => 'Genie game ' . $invite,
      'status' => 0,
      'field_genie_invite' => $invite,
    ]);
    $node->save();
    return $node;
  }

  /**
   * Finds the one game with these values, NULL when there is none.
   *
   * @param array<string, string> $properties
   *   Field values the game has.
   */
  private function one(array $properties): ?NodeInterface {
    $found = $this->entityTypeManager->getStorage('node')->loadByProperties(['type' => 'genie_game'] + $properties);
    return $found === [] ? NULL : reset($found);
  }

  /**
   * Creates a player account in a game, with a password nobody knows.
   */
  private function seat(int $game, string $name): UserInterface {
    $accountName = self::checkName($name) . '@' . $game;
    $users = $this->entityTypeManager->getStorage('user');
    if ($users->loadByProperties(['name' => $accountName]) !== []) {
      throw new \InvalidArgumentException(sprintf('Someone in this game is already called %s.', trim($name)), self::REFUSED['taken']);
    }
    /** @var \Drupal\user\UserInterface $account */
    $account = $users->create(['name' => $accountName, 'status' => 1, 'field_genie_game' => $game]);
    $account->setPassword(bin2hex(random_bytes(24)));
    $account->addRole('genie_player');
    $account->save();
    return $account;
  }

  /**
   * Counts a game's players.
   */
  private function players(int $game): int {
    return (int) $this->entityTypeManager->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_genie_game', $game)
      ->count()
      ->execute();
  }

}
