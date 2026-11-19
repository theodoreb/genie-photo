#!/usr/bin/env php
<?php

// Starts a game, signs in as its host like an MCP client, and calls my_status on Drupal.
// With --bad-token, checks that Drupal answers 401 with a Bearer challenge.

declare(strict_types=1);

use App\Drupal\DrupalToolFailed;
use App\Drupal\McpDrupalTools;
use App\Tests\Smoke\PlayerSession;
use App\Tests\Smoke\Seats;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpClient\HttpClient;

require dirname(__DIR__, 2).'/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');

$endpoint = $_ENV['DRUPAL_URL'].'/mcp';

if ('--bad-token' === ($argv[1] ?? '')) {
    $response = HttpClient::create()->request('POST', $endpoint, ['json' => [], 'headers' => ['Authorization' => 'Bearer nope']]);
    $challenge = $response->getHeaders(false)['www-authenticate'][0] ?? '';
    $refused = 401 === $response->getStatusCode() && str_starts_with($challenge, 'Bearer');
    echo $refused ? "refused with a challenge\n" : "NOT REFUSED: {$response->getStatusCode()}\n";
    exit($refused ? 0 : 1);
}

$player = $argv[1] ?? 'alice';
$seats = Seats::start($player);
$token = (new PlayerSession($endpoint))->signIn($seats->account($player), $seats->password($player));
try {
    $name = (new McpDrupalTools($endpoint, new Psr16Cache(new ArrayAdapter())))->call($token, 'my_status')['name'];
    echo $name, "\n";
    exit($name === $player ? 0 : 1);
} catch (DrupalToolFailed $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}
