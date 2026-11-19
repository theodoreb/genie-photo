<?php

declare(strict_types=1);

namespace App\Drupal;

use Mcp\Client\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Exception\ExceptionInterface as McpException;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Opens one MCP connection per call, carrying the player's own token.
 *
 * Drupal then runs each tool as that player, so it enforces ownership itself.
 */
final readonly class McpDrupalTools
{
    // A local text model can take minutes on a CPU.
    private const REQUEST_TIMEOUT_SECONDS = 300;

    // A rendered picture of a detailed photo can pass the client's 8 MB default once in base64.
    private const MAX_RESPONSE_BYTES = 32 * 1024 * 1024;

    public function __construct(
        #[Autowire('%env(DRUPAL_URL)%/mcp')]
        private string $endpoint,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     *
     * @throws DrupalToolFailed when Drupal cannot be reached or the tool reports an error
     */
    public function call(string $bearerToken, string $tool, array $arguments = []): array
    {
        return $this->invoke($bearerToken, $tool, $arguments)->structuredContent
            ?? throw new DrupalToolFailed(\sprintf('Drupal tool "%s" returned no data.', $tool));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return string the bytes of the image the tool returned
     *
     * @throws DrupalToolFailed when Drupal cannot be reached or the tool reports an error
     */
    public function image(string $bearerToken, string $tool, array $arguments = []): string
    {
        foreach ($this->invoke($bearerToken, $tool, $arguments)->content as $content) {
            if ($content instanceof ImageContent) {
                return base64_decode($content->data, true)
                    ?: throw new DrupalToolFailed(\sprintf('Drupal tool "%s" returned a broken image.', $tool));
            }
        }

        throw new DrupalToolFailed(\sprintf('Drupal tool "%s" returned no image.', $tool));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function invoke(string $bearerToken, string $tool, array $arguments): CallToolResult
    {
        $client = Client::builder()->setRequestTimeout(self::REQUEST_TIMEOUT_SECONDS)->build();
        $transport = new HttpTransport(
            $this->endpoint,
            headers: ['Authorization' => 'Bearer '.$bearerToken],
            maxSseBufferBytes: self::MAX_RESPONSE_BYTES,
        );

        try {
            $client->connect($transport);
            $result = $client->callTool($tool, $arguments);
        } catch (McpException $e) {
            throw new DrupalToolFailed(\sprintf('Drupal tool "%s" failed: %s', $tool, $e->getMessage()), previous: $e);
        } finally {
            $client->disconnect();
        }

        if ($result->isError) {
            $text = $result->content[0] instanceof TextContent ? $result->content[0]->text : 'unknown error';

            throw new DrupalToolFailed($text);
        }

        return $result;
    }
}
