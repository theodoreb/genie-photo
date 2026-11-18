<?php

declare(strict_types=1);

namespace App\Drupal;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Moves photo bytes to and from Drupal, outside MCP.
 */
final readonly class DrupalPhotos
{
    public function __construct(
        private HttpClientInterface $http,
        #[Autowire('%env(DRUPAL_URL)%/genie')]
        private string $endpoint,
    ) {
    }

    /**
     * @throws DrupalToolFailed when Drupal refuses the image
     */
    public function put(string $bearerToken, string $bytes): void
    {
        try {
            $this->http->request('PUT', $this->endpoint.'/photo', [
                'auth_bearer' => $bearerToken,
                'headers' => ['Content-Type' => 'application/octet-stream'],
                'body' => $bytes,
            ])->getContent();
        } catch (HttpException $e) {
            throw new DrupalToolFailed('Drupal refused the photo.', previous: $e);
        }
    }

    public function get(string $bearerToken): string
    {
        return $this->http->request('GET', $this->endpoint.'/photo', ['auth_bearer' => $bearerToken])->getContent();
    }

    public function frame(string $bearerToken, int $revision): string
    {
        return $this->http->request('GET', $this->endpoint.'/frame/'.$revision, ['auth_bearer' => $bearerToken])->getContent();
    }
}
