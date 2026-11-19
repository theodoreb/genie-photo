<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Game;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Names the game node this request works on.
 *
 * A player's account references the one game it belongs to. Drush commands
 * and the join form enter a game by node id instead.
 */
final class CurrentGame {

  /**
   * The game entered by node id, which wins over the account's.
   */
  private ?int $entered = NULL;

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns the game's node id, NULL for an account outside games.
   */
  public function find(): ?int {
    if ($this->entered !== NULL) {
      return $this->entered;
    }
    /** @var \Drupal\user\UserInterface|null $account */
    $account = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    $game = $account?->get('field_genie_game')->target_id;
    return $game === NULL ? NULL : (int) $game;
  }

  /**
   * Returns the game's node id.
   */
  public function id(): int {
    return $this->find() ?? throw new \RuntimeException('This account belongs to no game.');
  }

  /**
   * Works on another game until the next call.
   */
  public function enter(int $game): void {
    $this->entered = $game;
  }

}
