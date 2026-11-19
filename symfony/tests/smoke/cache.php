#!/usr/bin/env php
<?php

// Reads the caching hints Drupal stamps on 2026-07-28 results, and checks
// them against the genie_site recipe. Then connects through the SDK client with a
// shared discovery cache and counts the requests each connect sends.

declare(strict_types=1);

use App\Tests\Smoke\CountingHttpClient;
use App\Tests\Smoke\SmokeSite;
use Mcp\Client\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\Enum\CacheScope;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$site = new SmokeSite('alice', 'bob');
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

$bob = $site->signInAsAssistant('bob');
$cache = new Psr16Cache(new ArrayAdapter());

/**
 * Connects one client with the token and returns the requests the connect sent and the transport.
 *
 * @return array{int, HttpTransport}
 */
$connect = static function (string $token) use ($site, $cache): array {
    $http = new CountingHttpClient(new Psr18Client(HttpClient::create(['max_redirects' => 0])));
    $transport = new HttpTransport($site->drupalUrl.'/mcp', headers: ['Authorization' => 'Bearer '.$token], httpClient: $http);
    $client = Client::builder()->setCache($cache)->build();
    $client->connect($transport);

    return [$http->sent, $transport];
};

[$aliceFirst] = $connect($alice);
[$aliceSecond] = $connect($alice);
[$bobSent, $bobTransport] = $connect($bob);
$bobPrivateKey = 'mcp.discover.'.$bobTransport->cacheIdentity(CacheScope::Private);

$checks = [
    'alice first connect sends 1 request' => 1 === $aliceFirst,
    'alice second connect sends 0 requests' => 0 === $aliceSecond,
    'bob connect sends 0 requests (public entry)' => 0 === $bobSent,
    'bob has no private entry' => !$cache->has($bobPrivateKey),
];
foreach ($checks as $label => $ok) {
    echo $label, ': ', $ok ? 'ok' : 'FAILED', "\n";
    $failed = $failed || !$ok;
}

exit($failed ? 1 : 0);
