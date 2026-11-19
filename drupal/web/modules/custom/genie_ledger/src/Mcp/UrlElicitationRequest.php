<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Mcp;

use Mcp\Schema\JsonRpc\Request;

/**
 * Asks a client to send its person to a page.
 *
 * The SDK's own request has no elicitationId, which Claude Code requires.
 */
final class UrlElicitationRequest extends Request {

  public function __construct(
    public readonly string $message,
    public readonly string $url,
    public readonly string $elicitationId,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getMethod(): string {
    return 'elicitation/create';
  }

  /**
   * {@inheritdoc}
   */
  protected static function fromParams(?array $params): static {
    return new self($params['message'], $params['url'], $params['elicitationId']);
  }

  /**
   * {@inheritdoc}
   */
  protected function getParams(): array {
    return [
      'mode' => 'url',
      'message' => $this->message,
      'url' => $this->url,
      'elicitationId' => $this->elicitationId,
    ];
  }

}
