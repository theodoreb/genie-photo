#!/usr/bin/env php
<?php

// Reads the caching hints Drupal stamps on 2026-07-28 results, and checks
// them against the genie_site recipe.

declare(strict_types=1);

use App\Tests\Smoke\SmokeSite;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$site = new SmokeSite('alice');
$alice = $site->signInAsAssistant('alice');

/**
 * Sends one 2026-07-28 request and returns its result.
 *
 * @return array<string, mixed>
 */
$request = static function (string $method, array $params = []) use ($site, $alice): array {
    $response = $site->http->request('POST', $site->drupalUrl.'/mcp', [
        'headers' => [
            'Authorization' => 'Bearer '.$alice,
            'Accept' => 'application/json',
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => $method,
        ] + (isset($params['uri']) ? ['Mcp-Name' => $params['uri']] : []),
        'json' => [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params + ['_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientInfo' => ['name' => 'smoke-cache', 'version' => '1'],
                'io.modelcontextprotocol/clientCapabilities' => (object) [],
            ]],
        ],
    ]);

    return $response->toArray()['result'];
};

$resources = $site->tools->call($alice, 'session_get');
$gameUri = 'genie://game/'.substr($resources['topic'], strlen('genie/session/'));

$expected = [
    'server/discover' => [[], 300000, 'public'],
    'tools/list' => [[], 0, 'private'],
    'resources/read' => [['uri' => $gameUri], 0, 'private'],
];
$failed = false;
foreach ($expected as $method => [$params, $ttl, $scope]) {
    $result = $request($method, $params);
    $seen = [$result['ttlMs'] ?? null, $result['cacheScope'] ?? null];
    echo $method, ': ttlMs=', var_export($seen[0], true), ' cacheScope=', var_export($seen[1], true), "\n";
    $failed = $failed || $seen !== [$ttl, $scope];
}

exit($failed ? 1 : 0);
