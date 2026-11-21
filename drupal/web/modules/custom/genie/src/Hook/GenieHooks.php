<?php

declare(strict_types=1);

namespace Drupal\genie\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\genie\Game\Games;
use Drupal\genie\Photo\PhotoStore;

/**
 * Hooks for the genie games.
 */
final class GenieHooks {

  public function __construct(
    private readonly Games $games,
    private readonly PhotoStore $photos,
  ) {}

  /**
   * Shows a player by the name they chose, without their game's id.
   */
  #[Hook('user_format_name_alter')]
  public function formatName(string &$name, AccountInterface $account): void {
    $name = Games::displayName($name);
  }

  /**
   * Tells a player how to sign in, since players never get a password.
   */
  #[Hook('form_user_login_form_alter')]
  public function loginHint(array &$form): void {
    $form['genie_player'] = [
      '#markup' => new TranslatableMarkup('Joined a genie game from an invite link? Players have no password. Connect your assistant from the browser where you joined, where you are still signed in.'),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
      '#weight' => -100,
    ];
  }

  /**
   * Abandons the games idle for a day.
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->games->abandonIdle();
  }

  /**
   * Drops the stored pictures of games in play, so a deploy redraws them.
   */
  #[Hook('cache_flush')]
  public function cacheFlush(): void {
    $this->photos->clearOpenFrames();
  }

}
