<?php

declare(strict_types=1);

namespace App\Drupal;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends the uploaded photo to Drupal, outside MCP.
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
            $this->http->request('PUT', $this->endpoint.'/photo?_format=json', [
                'auth_bearer' => $bearerToken,
                'headers' => ['Content-Type' => 'application/octet-stream'],
                'body' => $bytes,
            ])->getContent();
        } catch (HttpExceptionInterface $e) {
            // Drupal answers a refusal with JSON that names the reason.
            $reason = json_decode($e->getResponse()->getContent(false), true)['message'] ?? 'Drupal refused the photo.';
            throw new DrupalToolFailed($reason, previous: $e);
        } catch (HttpException $e) {
            throw new DrupalToolFailed('Drupal refused the photo.', previous: $e);
        }
    }
}
