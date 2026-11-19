<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Game;

use Drupal\genie_ledger\Photo\Places;
use Drupal\genie_ledger\Web\WebViewAddress;

/**
 * Records each player's approval of the picture and asks for the missing ones.
 */
final class Approvals {

  private const RECEIPT_PROPERTIES = [
    'hash' => ['type' => 'string'],
    'revision' => ['type' => 'integer'],
    'update' => ['type' => 'integer'],
    'approved_by_you' => ['type' => 'boolean'],
    'waiting_for' => ['type' => 'array', 'items' => ['type' => 'string']],
    'final' => ['type' => 'boolean'],
  ];

  private const RECEIPT_REQUIRED = ['hash', 'revision', 'update', 'approved_by_you', 'waiting_for', 'final'];

  /**
   * What an approval on the web view returns.
   */
  public const RECEIPT_SCHEMA = [
    'type' => 'object',
    'properties' => self::RECEIPT_PROPERTIES,
    'required' => self::RECEIPT_REQUIRED,
  ];

  /**
   * What a request for approval returns.
   */
  public const APPROVAL_REQUEST_SCHEMA = [
    'type' => 'object',
    'properties' => self::RECEIPT_PROPERTIES + [
      'status' => ['type' => 'string', 'enum' => ['approved', 'waiting_on_web', 'picture_changed']],
      'reply' => ['type' => 'string'],
      'review_url' => ['type' => ['string', 'null']],
    ],
    'required' => [...self::RECEIPT_REQUIRED, 'status', 'reply', 'review_url'],
  ];

  private const APPROVED = 'Your person approved this picture.';

  private const POLL_MICROSECONDS = 500_000;

  /**
   * How long a request waits for the person.
   *
   * Claude Code 2.1.328 fails a silent call after about 60 seconds.
   */
  private const APPROVAL_WAIT_SECONDS = 45;

  public function __construct(
    private readonly Ledger $ledger,
    private readonly Exports $exports,
    private readonly WebViewAddress $webView,
  ) {}

  /**
   * Records a player's approval of the picture with this hash.
   *
   * @return array<string, mixed>
   *   The state of the export.
   *
   * @throws \InvalidArgumentException
   *   When the player takes no part or the picture has changed.
   */
  public function approve(int $uid, string $hash): array {
    $seen = $this->ledger->read();
    if (!isset($seen->players[$uid])) {
      throw new \InvalidArgumentException(Places::NO_PLACE);
    }
    $export = $this->exports->current($seen);
    if ($export === NULL || !hash_equals($export->hash, $hash)) {
      throw new \InvalidArgumentException('The picture has changed. Look at it again.');
    }
    $session = $this->ledger->write(static function (Session $session) use ($uid, $hash, $seen): void {
      if ($session->revision !== $seen->revision) {
        throw new \InvalidArgumentException('The picture has changed. Look at it again.');
      }
      $session->approvals[$uid] = $hash;
      if ($session->waiting($hash) === []) {
        $session->transition = 'close';
      }
    }, FALSE);
    return $session->receipt($uid, $export->hash);
  }

  /**
   * Describes what a player has to approve and where.
   *
   * @return array<string, mixed>
   *   The export state, a status, a reply and the page's address.
   *
   * @throws \InvalidArgumentException
   *   When the caller is no player or no picture exists.
   */
  public function approvalRequest(int $uid, string $name): array {
    $session = $this->ledger->read();
    if (!isset($session->players[$uid])) {
      throw new \InvalidArgumentException(Places::NO_PLACE);
    }
    $export = $session->photo === NULL ? NULL : $this->exports->current($session);
    if ($export === NULL) {
      throw new \InvalidArgumentException('There is no photo to approve right now.');
    }
    $state = $session->receipt($uid, $export->hash);
    if ($state['approved_by_you']) {
      return $this->approved($state);
    }
    return [
      'status' => 'waiting_on_web',
      'reply' => 'Nothing is approved yet. Give your person review_url. Then call wait_for_update with update as after_update, and call approve_export again.',
      'review_url' => $this->reviewUrl($session->revision, $export->hash, $name),
    ] + $state;
  }

  /**
   * Waits for the person to approve on the page.
   *
   * @param int $uid
   *   The player.
   * @param array<string, mixed> $request
   *   What approvalRequest() returned.
   *
   * @return array<string, mixed>
   *   The same shape, updated when the approval landed or the picture changed.
   */
  public function awaitApproval(int $uid, array $request): array {
    $deadline = microtime(TRUE) + self::APPROVAL_WAIT_SECONDS;
    do {
      $stored = $this->ledger->stored();
      $receipt = $stored->receipt($uid, $request['hash']);
      if ($stored->revision !== $request['revision']) {
        return [
          'status' => 'picture_changed',
          'reply' => 'The picture changed before your person approved it. Call approve_export again.',
          'review_url' => NULL,
        ] + $receipt;
      }
      if ($receipt['approved_by_you']) {
        return $this->approved($receipt);
      }
      usleep(self::POLL_MICROSECONDS);
    } while (microtime(TRUE) < $deadline);
    return $request;
  }

  /**
   * Adds the outcome of an approval that landed to its state.
   *
   * @param array<string, mixed> $state
   *   The approval state.
   *
   * @return array<string, mixed>
   *   The result of approve_export.
   */
  private function approved(array $state): array {
    return ['status' => 'approved', 'reply' => self::APPROVED, 'review_url' => NULL] + $state;
  }

  /**
   * Builds the address of the page that shows one picture for one player.
   */
  private function reviewUrl(int $revision, string $hash, string $name): string {
    return sprintf('%s/review/%d/%s?for=%s', $this->webView->origin(), $revision, $hash, rawurlencode($name));
  }

}
