<?php

declare(strict_types=1);

namespace Drupal\genie_ledger;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads and writes the session document.
 */
final class Ledger {

  private const LOCK = 'genie_ledger';
  private const KEY = 'session';

  /**
   * The store holding the session document.
   */
  private readonly KeyValueStoreInterface $store;

  public function __construct(
    KeyValueFactoryInterface $keyValue,
    #[Autowire(service: 'lock')]
    private readonly LockBackendInterface $lock,
    private readonly RevisionPublisher $publisher,
    private readonly PhotoStore $photos,
  ) {
    $this->store = $keyValue->get('genie');
  }

  /**
   * Returns the current session, with the media revision it points to.
   */
  public function read(): Session {
    $session = $this->store->get(self::KEY) ?? new Session();
    if ($session->surveyRevision !== NULL) {
      $session->pin($this->photos->survey($session->surveyRevision));
    }
    return $session;
  }

  /**
   * Returns the change counters, without loading the photo's media item.
   *
   * @return array{update: int, revision: int}
   *   The counters.
   */
  public function counters(): array {
    $session = $this->store->get(self::KEY) ?? new Session();
    return ['update' => $session->update, 'revision' => $session->revision];
  }

  /**
   * Applies a change under the lock and announces it.
   *
   * A changed survey becomes a new media revision first. Storing the session,
   * which then points to that revision, commits the change.
   *
   * @param callable(\Drupal\genie_ledger\Session): void $change
   *   Changes the session. An exception leaves the stored session untouched.
   * @param bool $changesPicture
   *   TRUE raises the revision and voids every approval.
   */
  public function write(callable $change, bool $changesPicture = TRUE): Session {
    $this->acquire();
    try {
      $session = $this->read();
      $before = $session->survey === NULL ? NULL : clone $session->survey;
      $change($session);
      if ($before !== NULL && $session->survey != $before) {
        $session->survey->revision = $this->photos->saveSurvey($session->survey);
      }
      if ($session->survey !== NULL) {
        $session->pin($session->survey);
      }
      $session->update++;
      if ($changesPicture) {
        $session->revision++;
        $session->approvals = [];
      }
      $this->store->set(self::KEY, $session);
    }
    finally {
      $this->lock->release(self::LOCK);
    }
    $this->publisher->publish($session->revision);
    return $session;
  }

  /**
   * Records what the detector found, unless the photo has changed meanwhile.
   *
   * @param int $media
   *   The media item the detector read.
   * @param string $status
   *   The detector's new status.
   * @param list<array{x: int, y: int, width: int, height: int}> $boxes
   *   The boxes it found.
   */
  public function recordDetection(int $media, string $status, array $boxes): void {
    $this->write(static function (Session $session) use ($media, $status, $boxes): void {
      if ($session->survey?->media === $media) {
        $session->survey->detector = $status;
        $session->survey->boxes = $boxes;
      }
    }, FALSE);
  }

  /**
   * Forgets the session, then the photo it pointed to.
   */
  public function reset(): void {
    $this->acquire();
    try {
      $this->store->delete(self::KEY);
      $this->photos->delete();
    }
    finally {
      $this->lock->release(self::LOCK);
    }
  }

  /**
   * Takes the lock, waiting once for a concurrent write to finish.
   */
  private function acquire(): void {
    if ($this->lock->acquire(self::LOCK)) {
      return;
    }
    if ($this->lock->wait(self::LOCK, 5) || !$this->lock->acquire(self::LOCK)) {
      throw new \RuntimeException('The genie session stayed locked.');
    }
  }

}
