<?php

declare(strict_types=1);

namespace Drupal\genie\Wish;

/**
 * What a wish asks for. The player comes from the signed-in account.
 */
enum Operation: string {

  case Cover = 'cover';
  case Exclude = 'exclude';
  case Show = 'visible';
  case Center = 'center_on';
  case StopCenter = 'stop_center';
  case Protect = 'protect';
  case Gold = 'gold';

  /**
   * Tells whether the wish changes how the player's own person looks.
   */
  public function isLook(): bool {
    return $this === self::Gold;
  }

}
