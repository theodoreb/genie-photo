<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Photo;

use Drupal\genie_ledger\Game\Ledger;
use Drupal\genie_ledger\Game\Session;

/**
 * Records who is in the photo and which place each player claims.
 */
final class People {

  public function __construct(
    private readonly Ledger $ledger,
  ) {}

  /**
   * Records what an assistant found in the photo.
   *
   * @return array{revision: int, unclaimed_people: int, objects: int, next: string}
   *   The outcome.
   *
   * @throws \InvalidArgumentException
   *   When the scan breaks a rule.
   */
  public function scan(int $uid, mixed $people, mixed $objects): array {
    $session = $this->ledger->write(static function (Session $session) use ($uid, $people, $objects): void {
      Places::scan($session, $uid, $people, $objects);
    });
    return [
      'revision' => $session->revision,
      'unclaimed_people' => count($session->proposals),
      'objects' => count($session->objectNames()),
      'next' => $session->next($uid),
    ];
  }

  /**
   * Gives the caller a place in the photo.
   *
   * @return array{revision: int, reply: string, next: string}
   *   The outcome.
   *
   * @throws \InvalidArgumentException
   *   When the place cannot be theirs.
   */
  public function claim(int $uid, string $name, mixed $person, mixed $head, mixed $body): array {
    $session = $this->ledger->write(static function (Session $session) use ($uid, $name, $person, $head, $body): void {
      Places::claim($session, $uid, $name, $person, $head, $body);
    });
    return [
      'revision' => $session->revision,
      'reply' => sprintf('Granted, %s. This place in the photo is yours.', $name),
      'next' => $session->next($uid),
    ];
  }

  /**
   * Removes a wrong proposal.
   *
   * @return array{revision: int}
   *   The new revision.
   *
   * @throws \InvalidArgumentException
   *   When the caller is not the uploader.
   */
  public function dismissProposal(int $uid, string $proposal): array {
    $session = $this->ledger->write(static function (Session $session) use ($uid, $proposal): void {
      if ($session->uploader !== $uid) {
        throw new \InvalidArgumentException('Only the uploader dismisses proposals.');
      }
      Places::dismiss($session, $proposal);
    });
    return ['revision' => $session->revision];
  }

}
