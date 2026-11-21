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
  case Disguise = 'disguise';
  case Gold = 'gold';
  case Cartoon = 'cartoon';

  /**
   * Tells whether the wish changes how the player's own person looks.
   */
  public function isLook(): bool {
    return in_array($this, [self::Disguise, self::Gold, self::Cartoon], TRUE);
  }

}
