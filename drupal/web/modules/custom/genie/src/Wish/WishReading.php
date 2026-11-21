<?php

declare(strict_types=1);

namespace Drupal\genie\Wish;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the local model read in a wish. A NULL operation stands for unclear.
 */
final readonly class WishReading {

  public function __construct(
    public Target $target,
    // The generated schema lists only the enum's values, which forbids null.
    #[Schema(enum: [
      Operation::Cover->value,
      Operation::Show->value,
      Operation::Disguise->value,
      Operation::Gold->value,
      Operation::Cartoon->value,
      Operation::Blur->value,
      NULL,
    ])]
    public ?Operation $operation,
  ) {}

  /**
   * Tells whether an operation concerns the speaker, the only one it may touch.
   */
  #[Assert\IsTrue(message: 'An operation is only for the speaker.')]
  public function isAboutSpeaker(): bool {
    return $this->operation === NULL || $this->target === Target::Me;
  }

}
