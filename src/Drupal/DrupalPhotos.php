<?php

declare(strict_types=1);

namespace App\Drupal;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\HttpException as HttpStatusException;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
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
        return $this->image($bearerToken, '/photo');
    }

    public function frame(string $bearerToken, int $revision): string
    {
        return $this->image($bearerToken, '/frame/'.$revision);
    }

    /**
     * @throws HttpStatusException with Drupal's status when it refuses
     */
    private function image(string $bearerToken, string $path): string
    {
        try {
            return $this->http->request('GET', $this->endpoint.$path, ['auth_bearer' => $bearerToken])->getContent();
        } catch (ClientExceptionInterface $e) {
            throw new HttpStatusException($e->getResponse()->getStatusCode(), 'Drupal refused the image.', $e);
        }
    }
}
