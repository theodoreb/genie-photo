<?php

declare(strict_types=1);

namespace Drupal\genie\Photo;

use Drupal\Core\DestructableInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use Drupal\genie\Game\CurrentGame;
use Drupal\genie\Game\Games;
use Drupal\genie\Game\Ledger;
use Drupal\genie\Game\Session;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps games open on the prepared pictures of `archive/manifest.json`.
 *
 * Each kind has its own turn, in the order of the manifest. Paintings and
 * historical photos come from Wikimedia Commons, stock photos from Pexels,
 * Unsplash and Burst. The first pictures ship with the module. The others
 * are fetched when their turn comes.
 */
final class Archive implements EventSubscriberInterface, DestructableInterface {

  /**
   * How many prepared games stay open, by the kind the manifest names.
   */
  public const OPEN = ['painting' => 1, 'photo' => 1, 'stock' => 4];

  private const LOCK = 'genie:archive';

  /**
   * State key prefix of the next position among one kind's entries.
   */
  private const NEXT = 'genie.archive.next.';

  private const API = 'https://commons.wikimedia.org/w/api.php';

  private const AGENT = 'genie-photo/1.0';

  /**
   * TRUE once a prepared game left the open state in this request.
   */
  private bool $due = FALSE;

  /**
   * The manifest, read once.
   *
   * @var list<array{id: string, title: string, name: string, kind: string, page: string, thumb: string, sha1: string, licence: string, author: string, date: string, width: int, height: int, boxes: list<array{x: int, y: int, width: int, height: int}>}>|null
   */
  private ?array $photos = NULL;

  public function __construct(
    private readonly Games $games,
    private readonly CurrentGame $game,
    private readonly Ledger $ledger,
    private readonly PhotoStore $store,
    private readonly PhotoIntake $intake,
    private readonly StateInterface $state,
    #[Autowire(service: 'lock')]
    private readonly LockBackendInterface $lock,
    private readonly ClientInterface $http,
    #[Autowire(service: 'extension.list.module')]
    private readonly ModuleExtensionList $modules,
    #[Autowire(service: 'logger.channel.genie')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'genie_game.close.post_transition' => 'onLeftOpen',
      'genie_game.abandon.post_transition' => 'onLeftOpen',
    ];
  }

  /**
   * Notes that a prepared game ended, so another opens after the response.
   */
  public function onLeftOpen(WorkflowTransitionEvent $event): void {
    $this->due = $this->due || !$event->getEntity()->get('field_genie_archive')->isEmpty();
  }

  /**
   * {@inheritdoc}
   */
  public function destruct(): void {
    if ($this->due) {
      $this->due = FALSE;
      $this->fill();
    }
  }

  /**
   * Opens prepared games until enough of each kind are open.
   *
   * A picture that cannot be fetched stops the round of its kind. The next
   * round starts with the picture after it.
   *
   * @return int
   *   How many games opened.
   */
  public function fill(): int {
    // Another request is filling.
    if (!$this->lock->acquire(self::LOCK, 300)) {
      return 0;
    }
    try {
      $opened = 0;
      foreach (self::OPEN as $kind => $count) {
        $photos = array_values(array_filter($this->manifest(), static fn (array $photo): bool => $photo['kind'] === $kind && $photo['rotation']));
        $open = $this->games->openArchives(array_column($photos, 'id'));
        for ($missing = $count - count($open); $missing > 0; $missing--) {
          [$position, $photo] = self::nextFree($photos, (int) $this->state->get(self::NEXT . $kind, 0), $open);
          $this->state->set(self::NEXT . $kind, $position);
          if ($photo === NULL) {
            break;
          }
          try {
            $this->open($photo);
          }
          catch (\RuntimeException $e) {
            $this->logger->warning('The archive picture @id did not open: @reason', [
              '@id' => $photo['id'],
              '@reason' => $e->getMessage(),
            ]);
            break;
          }
          $open[] = $photo['id'];
          $opened++;
        }
      }
      return $opened;
    }
    finally {
      $this->lock->release(self::LOCK);
    }
  }

  /**
   * Picks the next picture in turn that has no open game.
   *
   * A picture still open waits for its next turn, so the list of games
   * never shows one picture twice.
   *
   * @param list<array<string, mixed>> $photos
   *   The pictures of one kind, in turn order.
   * @param int $position
   *   The position of the next turn.
   * @param list<string> $open
   *   The archive ids of the open games.
   *
   * @return array{0: int, 1: array<string, mixed>|null}
   *   The position after the pick, and the picture, or NULL when every
   *   picture has an open game.
   */
  public static function nextFree(array $photos, int $position, array $open): array {
    for ($turn = $position; $turn < $position + count($photos); $turn++) {
      $photo = $photos[$turn % count($photos)];
      if (!in_array($photo['id'], $open, TRUE)) {
        return [$turn + 1, $photo];
      }
    }
    return [$position, NULL];
  }

  /**
   * Says where a prepared photo comes from.
   *
   * @return array{title: string, author: string, date?: string, licence: string, page: string, source: string}
   *   What to credit, and the photo's page at its source. A stock photo has
   *   no date.
   */
  public function credit(string $id): array {
    $photo = $this->manifest()[array_search($id, array_column($this->manifest(), 'id'), TRUE)];
    return ['title' => $photo['name']]
      + array_intersect_key($photo, array_flip(['author', 'date', 'licence', 'page', 'source']));
  }

  /**
   * Opens a game on one photo, with the people the manifest lists.
   *
   * @param array<string, mixed> $photo
   *   The manifest entry.
   *
   * @throws \RuntimeException
   *   The photo could not be fetched, or is no longer the one prepared.
   */
  private function open(array $photo): void {
    try {
      $normal = $this->intake->normalize($this->bytes($photo));
    }
    catch (\InvalidArgumentException $e) {
      throw new \RuntimeException($e->getMessage(), previous: $e);
    }
    // The boxes are in the pixels of the photo as it was prepared.
    if ([$normal['width'], $normal['height']] !== [$photo['width'], $photo['height']]) {
      throw new \RuntimeException('Its size changed since it was prepared.');
    }
    $this->game->enter($this->games->prepare($photo['id']));
    $this->ledger->write(function (Session $session) use ($normal, $photo): void {
      $session->transition = 'open';
      $session->pin($this->store->create($normal['jpeg'], 0, 'ready', $photo['boxes']));
    });
    // A visitor without an assistant claims one of these on the page. An
    // assistant's scan replaces each one whose head it finds again. A write
    // keeps the people it adds only to a survey stored before it.
    $this->ledger->write(static function (Session $session): void {
      $detected = Places::detected($session);
      Places::scan($session, array_map(self::person(...), array_keys($detected), $detected), guesses: TRUE);
    });
  }

  /**
   * Describes a detected person, with a head guessed from the body's top.
   *
   * @param string $label
   *   The detector's label: P1, P2 and so on, from left to right.
   * @param array{x: int, y: int, width: int, height: int} $body
   *   The detector's box.
   *
   * @return array{detected: string, description: string, head: list<int>}
   *   The person, as a scan reports one.
   */
  private static function person(string $label, array $body): array {
    // A head is about an eighth of a standing body, a fifth of a seated one.
    $side = (int) round(min($body['width'] * 0.42, $body['height'] * 0.2));
    $left = $body['x'] + intdiv($body['width'] - $side, 2);
    $top = $body['y'] + (int) round($body['height'] * 0.02);
    return [
      'detected' => $label,
      'description' => 'Person ' . substr($label, 1),
      'head' => [$left, $top, $side, $side],
    ];
  }

  /**
   * Reads a photo from the module, from a stock site or from Commons.
   *
   * @param array<string, mixed> $photo
   *   The manifest entry.
   *
   * @throws \RuntimeException
   *   The source did not answer, or holds another file than the one prepared.
   */
  private function bytes(array $photo): string {
    $shipped = $this->folder() . '/' . $photo['id'] . '.jpg';
    if (is_file($shipped)) {
      return file_get_contents($shipped);
    }
    $options = ['headers' => ['User-Agent' => self::AGENT], 'timeout' => 30];
    try {
      if (isset($photo['sha256'])) {
        return self::verified((string) $this->http->request('GET', $photo['file'], $options)->getBody(), $photo['sha256']);
      }
      $info = json_decode((string) $this->http->request('GET', self::API, $options + [
        'query' => [
          'action' => 'query',
          'format' => 'json',
          'formatversion' => 2,
          'titles' => $photo['title'],
          'prop' => 'imageinfo',
          'iiprop' => 'sha1',
        ],
      ])->getBody(), TRUE, flags: JSON_THROW_ON_ERROR);
      // A file uploaded again under the same title is another picture.
      if (($info['query']['pages'][0]['imageinfo'][0]['sha1'] ?? NULL) !== $photo['sha1']) {
        throw new \RuntimeException('Wikimedia Commons holds another file under this title.');
      }
      return (string) $this->http->request('GET', $photo['thumb'], $options)->getBody();
    }
    catch (GuzzleException | \JsonException $e) {
      throw new \RuntimeException($e->getMessage(), previous: $e);
    }
  }

  /**
   * Returns a fetched stock photo when it is still the file prepared.
   *
   * @throws \RuntimeException
   *   The stock site serves another file at this address.
   */
  public static function verified(string $bytes, string $sha256): string {
    if (!hash_equals($sha256, hash('sha256', $bytes))) {
      throw new \RuntimeException('The stock site serves another file at this address.');
    }
    return $bytes;
  }

  /**
   * Returns the manifest's photos, in the order they open.
   *
   * @return list<array<string, mixed>>
   *   The entries.
   */
  private function manifest(): array {
    return $this->photos ??= json_decode(file_get_contents($this->folder() . '/manifest.json'), TRUE, flags: JSON_THROW_ON_ERROR);
  }

  /**
   * Returns the folder of the manifest and the photos that ship with it.
   */
  private function folder(): string {
    return DRUPAL_ROOT . '/' . $this->modules->getPath('genie') . '/archive';
  }

}
