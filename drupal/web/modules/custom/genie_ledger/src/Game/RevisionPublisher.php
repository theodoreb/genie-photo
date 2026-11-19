<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Game;

/**
 * Announces a new revision on the Mercure hub.
 */
final class RevisionPublisher {

  public const TOPIC = 'genie/session/';

  /**
   * Publishes the revision number, the only data the public topic carries.
   */
  public function publish(string $game, int $revision): void {
    // FrankenPHP defines this function in web requests only.
    if (function_exists('mercure_publish')) {
      mercure_publish(self::TOPIC . $game, json_encode(['revision' => $revision], JSON_THROW_ON_ERROR));
    }
  }

}
