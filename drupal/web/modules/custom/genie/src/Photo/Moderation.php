<?php

declare(strict_types=1);

namespace Drupal\genie\Photo;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\State\StateInterface;
use Drupal\genie\Game\CurrentGame;
use Drupal\genie\Game\Exports;
use Drupal\genie\Game\Ledger;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Withholds sexual final pictures from the public pages.
 *
 * A local model scores every final picture. OpenAI also reviews the pictures
 * of prepared games, whose paintings and photos are public domain, when an
 * API key is set. Either one withholds a picture.
 */
final class Moderation {

  /**
   * NSFW score from which the local model withholds a picture.
   */
  public const THRESHOLD = 0.5;

  /**
   * Pictures one run sends to each check.
   */
  private const PER_RUN = 20;

  /**
   * Key-value collection of the verdicts, by game id.
   *
   * A verdict holds "local", the NSFW score, and "openai", whether OpenAI saw
   * sexual content. Each is NULL for a game that ended with no picture.
   */
  private const VERDICTS = 'genie.moderation';

  /**
   * Starts the key of each picture sent to a check, before the game id.
   */
  private const KEY = 'game-';

  /**
   * State key of the OpenAI batch in flight.
   */
  private const BATCH = 'genie.moderation.openai_batch';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CurrentGame $game,
    private readonly Ledger $ledger,
    private readonly Exports $exports,
    #[Autowire(service: 'keyvalue')]
    private readonly KeyValueFactoryInterface $keyValue,
    private readonly StateInterface $state,
    private readonly NsfwClassifier $classifier,
    private readonly OpenAiReview $review,
    #[Autowire(service: 'logger.channel.genie')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Checks the final pictures no check has seen yet.
   */
  public function run(): void {
    $this->scoreLocally();
    if ($this->review->enabled()) {
      $this->reviewWithOpenAi();
    }
  }

  /**
   * Tells whether a game's final picture is kept from the public pages.
   */
  public function withheld(int $game): bool {
    $verdict = $this->verdicts()->get((string) $game, []);
    return ($verdict['local'] ?? 0.0) >= self::THRESHOLD || ($verdict['openai'] ?? FALSE);
  }

  /**
   * Lists the withheld games.
   *
   * @return array<int, array{local?: float|null, openai?: bool|null}>
   *   The verdicts, by game id.
   */
  public function flagged(): array {
    $flagged = [];
    foreach ($this->verdicts()->getAll() as $game => $verdict) {
      if ($this->withheld((int) $game)) {
        $flagged[(int) $game] = $verdict;
      }
    }
    return $flagged;
  }

  /**
   * Scores the pictures the local model has not seen, in one model run.
   */
  private function scoreLocally(): void {
    $pictures = $this->unchecked('local', $this->finishedGames(FALSE));
    if ($pictures === []) {
      return;
    }
    // Batch code skips what it cannot check and reports the count.
    if (!$this->classifier->available()) {
      $this->logger->warning('@count final pictures wait for the NSFW model, which needs PHP FFI.', ['@count' => count($pictures)]);
      return;
    }
    // A failed run is logged, and its pictures wait for the next one.
    foreach ($this->classifier->scoreInCommand($pictures) ?? [] as $key => $score) {
      $this->record(self::gameOf($key), 'local', $score);
    }
  }

  /**
   * Reads the OpenAI batch in flight, or starts one.
   */
  private function reviewWithOpenAi(): void {
    $batch = $this->state->get(self::BATCH);
    if ($batch !== NULL) {
      $answers = $this->review->collect($batch);
      if ($answers === NULL) {
        return;
      }
      foreach ($answers as $key => $sexual) {
        $this->record(self::gameOf($key), 'openai', $sexual);
      }
      // The next run sends again what OpenAI did not answer.
      $this->state->delete(self::BATCH);
      return;
    }
    $pictures = $this->unchecked('openai', $this->finishedGames(TRUE));
    if ($pictures !== []) {
      $this->state->set(self::BATCH, $this->review->submit($pictures));
    }
  }

  /**
   * Collects the final pictures one check has not seen.
   *
   * A game that ended with no picture shows its original photo, so it gets a
   * NULL verdict at once.
   *
   * @param string $check
   *   The check: "local" or "openai".
   * @param list<int> $games
   *   The finished games.
   *
   * @return array<string, string>
   *   PNG bytes, by KEY and the game id.
   */
  private function unchecked(string $check, array $games): array {
    $verdicts = $this->verdicts();
    $pictures = [];
    foreach ($games as $game) {
      if (count($pictures) === self::PER_RUN) {
        break;
      }
      if (array_key_exists($check, $verdicts->get((string) $game, []))) {
        continue;
      }
      $this->game->enter($game);
      $export = $this->exports->current($this->ledger->read());
      if ($export === NULL) {
        $this->record($game, $check, NULL);
        continue;
      }
      $pictures[self::KEY . $game] = $export->png;
    }
    return $pictures;
  }

  /**
   * Lists the finished games that have a photo, the latest first.
   *
   * @param bool $prepared
   *   TRUE for prepared games only.
   *
   * @return list<int>
   *   Node ids.
   */
  private function finishedGames(bool $prepared): array {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'genie_game')
      ->condition('field_genie_state', Ledger::OVER, 'IN')
      ->exists('field_genie_photo')
      ->sort('changed', 'DESC');
    if ($prepared) {
      $query->exists('field_genie_archive');
    }
    return array_map(intval(...), array_values($query->execute()));
  }

  /**
   * Stores one check's verdict on a game.
   */
  private function record(int $game, string $check, float|bool|null $verdict): void {
    $verdicts = $this->verdicts();
    $verdicts->set((string) $game, [$check => $verdict] + $verdicts->get((string) $game, []));
  }

  /**
   * Returns the verdict store.
   */
  private function verdicts(): KeyValueStoreInterface {
    return $this->keyValue->get(self::VERDICTS);
  }

  /**
   * Reads the game id back from a picture key.
   */
  private static function gameOf(string $key): int {
    return (int) substr($key, strlen(self::KEY));
  }

}
