<?php

declare(strict_types=1);

namespace Drupal\genie\Web;

use Drupal\simple_oauth_server_metadata\Event\ResourceMetadataEvent;
use Drupal\simple_oauth_server_metadata\Event\ResourceMetadataEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Names the player scope in the MCP endpoint's resource metadata.
 *
 * MCP clients ask for the scopes this metadata lists. Without it they ask for
 * every scope the authorization server lists, including the web view's
 * `genie_view`, which only the client credentials grant accepts.
 */
final class PlayerScope implements EventSubscriberInterface {

  /**
   * The scope a player's assistant signs in with.
   */
  private const SCOPE = 'genie';

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ResourceMetadataEvents::BUILD => 'onBuild'];
  }

  /**
   * Adds the player scope.
   */
  public function onBuild(ResourceMetadataEvent $event): void {
    $event->addMetadataField('scopes_supported', [self::SCOPE]);
  }

}
