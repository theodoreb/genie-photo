<?php

declare(strict_types=1);

namespace App\Drupal;

/**
 * Calls an MCP tool on the Drupal ledger as the owner of a bearer token.
 */
interface DrupalTools
{
    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     *
     * @throws DrupalToolFailed when Drupal cannot be reached or the tool reports an error
     */
    public function call(string $bearerToken, string $tool, array $arguments = []): array;
}
