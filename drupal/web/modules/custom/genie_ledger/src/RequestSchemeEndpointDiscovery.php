<?php

declare(strict_types=1);

namespace Drupal\genie_ledger;

use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Url;
use Drupal\simple_oauth_server_metadata\Service\EndpointDiscoveryService;

/**
 * Reports the issuer with the scheme of the current request.
 *
 * The parent forces HTTPS. A loopback site without a trusted local certificate
 * serves HTTP, and clients compare the issuer with the address they fetched.
 */
final class RequestSchemeEndpointDiscovery extends EndpointDiscoveryService {

  /**
   * {@inheritdoc}
   */
  public function getIssuer(): string {
    return rtrim(Url::fromUri('internal:/', [
      'absolute' => TRUE,
      'language' => new Language(['id' => LanguageInterface::LANGCODE_NOT_APPLICABLE]),
    ])->toString(), '/');
  }

}
