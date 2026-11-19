#!/usr/bin/env php
<?php

// Listens to alice's game resource on the 2026-07-28 revision while bob
// wishes, and checks that the update reaches the stream.

declare(strict_types=1);

use App\Tests\Smoke\SmokeSite;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$site = new SmokeSite();
$alice = $site->signInAsAssistant('alice');
$bob = $site->signIn('bob');
$site->uploadPhoto($site->signIn('alice'));
$site->markPlace($bob, 1200);

$resources = $site->tools->call($alice, 'session_get');
$uri = 'genie://game/'.substr($resources['topic'], strlen('genie/session/'));
$meta = [
    'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
    'io.modelcontextprotocol/clientInfo' => ['name' => 'smoke-subscribe', 'version' => '1'],
    'io.modelcontextprotocol/clientCapabilities' => (object) [],
];
$stream = $site->http->request('POST', $site->drupalUrl.'/mcp', [
    'headers' => [
        'Authorization' => 'Bearer '.$alice,
        'Accept' => 'application/json, text/event-stream',
        'MCP-Protocol-Version' => '2026-07-28',
        'Mcp-Method' => 'subscriptions/listen',
    ],
    'json' => [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'subscriptions/listen',
        'params' => ['_meta' => $meta, 'notifications' => ['resourceSubscriptions' => [$uri]]],
    ],
    'timeout' => 20,
]);

$changed = false;
foreach ($site->http->stream($stream, 20) as $chunk) {
    if ($chunk->isTimeout() || $chunk->isLast()) {
        break;
    }
    $content = $chunk->getContent();
    if (!$changed && str_contains($content, 'notifications/subscriptions/acknowledged')) {
        if (!str_contains($content, $uri)) {
            SmokeSite::fail('The server did not agree to the game resource: '.$content);
        }
        $site->tools->call($bob, 'make_wish', ['wish' => 'hide my face']);
        $changed = true;
    }
    if ($changed && str_contains($content, 'notifications/resources/updated') && str_contains($content, $uri)) {
        echo "alice's stream got the update of $uri\n";
        exit(0);
    }
}
SmokeSite::fail('No resource update reached the stream.');
