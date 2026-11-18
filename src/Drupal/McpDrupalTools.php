<?php

declare(strict_types=1);

namespace App\Drupal;

use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Exception\ExceptionInterface as McpException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Opens one MCP connection per call, carrying the player's own token.
 *
 * Drupal then runs each tool as that player, so it enforces ownership itself.
 */
final readonly class McpDrupalTools implements DrupalTools
{
    public function __construct(
        #[Autowire('%env(DRUPAL_URL)%/mcp')]
        private string $endpoint,
    ) {
    }

    public function call(string $bearerToken, string $tool, array $arguments = []): array
    {
        $client = Client::builder()->build();
        $transport = new HttpTransport($this->endpoint, headers: ['Authorization' => 'Bearer '.$bearerToken]);

        try {
            $client->connect($transport);
            $result = $client->callTool($tool, $arguments);
        } catch (McpException $e) {
            throw new DrupalToolFailed(\sprintf('Drupal tool "%s" failed: %s', $tool, $e->getMessage()), previous: $e);
        } finally {
            $client->disconnect();
        }

        if ($result->isError || !\is_array($result->structuredContent)) {
            throw new DrupalToolFailed(\sprintf('Drupal tool "%s" returned an error or no structured result.', $tool));
        }

        return $result->structuredContent;
    }
}
