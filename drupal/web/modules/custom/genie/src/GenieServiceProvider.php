<?php

declare(strict_types=1);

namespace Drupal\genie;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\genie\Web\RequestSchemeEndpointDiscovery;

/**
 * Swaps in the issuer that matches the address the site really serves.
 */
final class GenieServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    $container->getDefinition('simple_oauth_server_metadata.endpoint_discovery')
      ->setClass(RequestSchemeEndpointDiscovery::class);
  }

}
