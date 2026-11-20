<?php

declare(strict_types=1);

namespace Drupal\genie\Web;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Knows where the web view is served, from its OAuth client.
 */
final class WebViewAddress {

  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * Returns the web view's scheme, host and port.
   */
  public function origin(): string {
    /** @var \Drupal\Core\Entity\ContentEntityInterface $client */
    [$client] = array_values($this->entityTypeManager->getStorage('consumer')->loadByProperties(['client_id' => WebViewAccess::CLIENT_ID]));
    // Scheme, the empty part between the slashes, and host with any port.
    return implode('/', array_slice(explode('/', $client->get('redirect')->value), 0, 3));
  }

  /**
   * Returns the page that signs this browser's Drupal account in.
   *
   * A browser that played another game holds that game's sign-in until then.
   */
  public function signIn(): string {
    return $this->origin() . '/login';
  }

}
