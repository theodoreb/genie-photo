<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Mcp;

use Mcp\Schema\JsonRpc\Notification;

/**
 * Tells a client that the page it opened has served its purpose.
 */
final class ElicitationCompleteNotification extends Notification {

  public function __construct(public readonly string $elicitationId) {}

  /**
   * {@inheritdoc}
   */
  public static function getMethod(): string {
    return 'notifications/elicitation/complete';
  }

  /**
   * {@inheritdoc}
   */
  protected static function fromParams(?array $params): static {
    return new self($params['elicitationId']);
  }

  /**
   * {@inheritdoc}
   */
  protected function getParams(): array {
    return ['elicitationId' => $this->elicitationId];
  }

}
