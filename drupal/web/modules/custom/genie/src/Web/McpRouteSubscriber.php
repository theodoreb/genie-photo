<?php

declare(strict_types=1);

namespace Drupal\genie\Web;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Makes OAuth the only way to reach the MCP endpoint.
 */
final class McpRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $collection->get('mcp_server.handle')?->setOption('_auth', ['oauth2']);
  }

}
