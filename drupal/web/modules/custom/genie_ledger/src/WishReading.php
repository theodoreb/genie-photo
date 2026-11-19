<?php

declare(strict_types=1);

namespace Drupal\genie_ledger;

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
      Operation::Exclude->value,
      Operation::Show->value,
      Operation::Center->value,
      Operation::StopCenter->value,
      Operation::Protect->value,
      NULL,
    ])]
    public ?Operation $operation,
    public ?string $object,
  ) {}

  /**
   * Tells whether an operation concerns the speaker, the only one it may touch.
   */
  #[Assert\IsTrue(message: 'An operation is only for the speaker.')]
  public function isAboutSpeaker(): bool {
    return $this->operation === NULL || $this->target === Target::Me;
  }

  /**
   * Tells whether a protect wish names its object.
   */
  #[Assert\IsTrue(message: 'A protect wish names an object.')]
  public function isObjectNamed(): bool {
    return $this->operation !== Operation::Protect || ($this->object ?? '') !== '';
  }

}
