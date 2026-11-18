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
  ) {
    $this->store = $keyValue->get('genie');
  }

  /**
   * Returns the current session.
   */
  public function read(): Session {
    return $this->store->get(self::KEY) ?? new Session();
  }

  /**
   * Applies a change under the lock and announces it.
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
      $change($session);
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
   * Forgets the session.
   */
  public function reset(): void {
    $this->acquire();
    try {
      $this->store->delete(self::KEY);
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
