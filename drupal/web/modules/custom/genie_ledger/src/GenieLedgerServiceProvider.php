<?php

declare(strict_types=1);

namespace Drupal\genie_ledger;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\genie_ledger\Web\RequestSchemeEndpointDiscovery;

/**
 * Swaps in the issuer that matches the address the site really serves.
 */
final class GenieLedgerServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    $container->getDefinition('simple_oauth_server_metadata.endpoint_discovery')
      ->setClass(RequestSchemeEndpointDiscovery::class);
  }

}
