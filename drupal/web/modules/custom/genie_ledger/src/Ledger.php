<?php

declare(strict_types=1);

namespace Drupal\genie_ledger;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\genie_ledger\Plugin\mcp_server\ResourceProvider\GameResource;
use Drupal\node\NodeInterface;
use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Schema\Notification\ToolListChangedNotification;
use Mcp\Server\Subscription\NotificationBusInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads and writes the play state of the current game node.
 */
final class Ledger {

  private const LOCK = 'genie_ledger:';

  /**
   * States where nothing changes any more.
   */
  public const OVER = ['closed', 'abandoned'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'lock')]
    private readonly LockBackendInterface $lock,
    private readonly RevisionPublisher $publisher,
    private readonly PhotoStore $photos,
    private readonly CurrentGame $game,
    #[Autowire(service: 'mcp_server.notification_bus')]
    private readonly NotificationBusInterface $bus,
  ) {}

  /**
   * Returns the current game's node, as stored now.
   */
  public function game(): NodeInterface {
    // A cached node would hide what other requests stored, as in a long wait.
    /** @var \Drupal\node\NodeInterface */
    return $this->entityTypeManager->getStorage('node')->loadUnchanged($this->game->id());
  }

  /**
   * Returns the state of the current game, NULL for an account outside games.
   */
  public function state(): ?string {
    return $this->game->find() === NULL ? NULL : $this->game()->get('field_genie_state')->value;
  }

  /**
   * Returns the current session, with the media revision it points to.
   */
  public function read(): Session {
    return $this->pinned(Session::fromGame($this->game()));
  }

  /**
   * Returns the change counters, without loading the photo's media item.
   *
   * @return array{update: int, revision: int}
   *   The counters.
   */
  public function counters(): array {
    $session = $this->stored();
    return ['update' => $session->update, 'revision' => $session->revision];
  }

  /**
   * Returns the stored session, without the photo's media item.
   *
   * Its survey and everything derived from it stay empty.
   */
  public function stored(): Session {
    return Session::fromGame($this->game());
  }

  /**
   * Applies a change under the game's lock and announces it.
   *
   * A changed survey becomes a new media revision first. Saving the node,
   * which then points to that revision, commits the change and its transition.
   *
   * @param callable(\Drupal\genie_ledger\Session): void $change
   *   Changes the session. An exception leaves the stored session untouched.
   * @param bool $changesPicture
   *   TRUE raises the revision and voids every approval.
   *
   * @throws \InvalidArgumentException
   *   The game is closed or abandoned.
   */
  public function write(callable $change, bool $changesPicture = TRUE): Session {
    [$game, $session] = $this->locked(function (NodeInterface $game) use ($change, $changesPicture): array {
      if (in_array($game->get('field_genie_state')->value, self::OVER, TRUE)) {
        throw new \InvalidArgumentException('This game is over. Its picture stays as it is.');
      }
      $session = $this->pinned(Session::fromGame($game));
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
      if ($session->transition !== NULL) {
        /** @var \Drupal\state_machine\Plugin\Field\FieldType\StateItemInterface $state */
        $state = $game->get('field_genie_state')->first();
        $state->applyTransitionById($session->transition);
      }
      if ($session->transition === 'open') {
        $game->set('field_genie_photo', $session->survey->media);
      }
      $game->set('field_genie_session', $session->toJson())->save();
      return [$game, $session];
    });
    $this->publisher->publish($game->uuid(), $session->revision);
    $this->bus->publish(new ResourceUpdatedNotification(GameResource::uri($game->uuid())));
    if ($session->transition !== NULL) {
      // The new state lists other tools.
      $this->bus->publish(new ToolListChangedNotification());
    }
    return $session;
  }

  /**
   * Runs work on the current game under its lock.
   *
   * @param callable(\Drupal\node\NodeInterface): T $work
   *   Receives the game node as stored now.
   *
   * @return T
   *   What the work returns.
   *
   * @template T
   */
  public function locked(callable $work): mixed {
    $game = $this->game->id();
    $lock = self::LOCK . $game;
    if (!$this->lock->acquire($lock) && ($this->lock->wait($lock, 5) || !$this->lock->acquire($lock))) {
      throw new \RuntimeException('The genie game stayed locked.');
    }
    try {
      return $work($this->game());
    }
    finally {
      $this->lock->release($lock);
    }
  }

  /**
   * Points a session at the media revision it names.
   */
  private function pinned(Session $session): Session {
    if ($session->surveyRevision !== NULL) {
      $session->pin($this->photos->survey($session->surveyRevision));
    }
    return $session;
  }

}
