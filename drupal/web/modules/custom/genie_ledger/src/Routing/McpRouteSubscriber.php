<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Routing;

use Drupal\Core\Routing\RouteBuildEvent;
use Drupal\Core\Routing\RoutingEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Makes OAuth the only way to reach the MCP endpoint.
 *
 * Runs after mcp_server_oauth, which adds oauth2 next to the cookie provider.
 */
final class McpRouteSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [RoutingEvents::ALTER => ['onAlter', -1000]];
  }

  /**
   * Removes every authentication provider but OAuth from the MCP route.
   */
  public function onAlter(RouteBuildEvent $event): void {
    $event->getRouteCollection()->get('mcp_server.handle')?->setOption('_auth', ['oauth2']);
  }

}
