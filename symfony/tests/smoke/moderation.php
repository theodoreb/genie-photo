#!/usr/bin/env php
<?php

// Critical path of moderation: the local model scores a closed game's final picture,
// and a flagged picture leaves the public pages for the original photo.

declare(strict_types=1);

use App\Drupal\DrupalToolFailed;
use App\Tests\Smoke\SmokeSite;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$site = new SmokeSite('alice', 'bob');
$alice = $site->signIn('alice');
$bob = $site->signIn('bob');
$site->uploadPhoto($alice);
$site->markPlace($alice, 100);
$site->markPlace($bob, 800);
$hash = $site->tools->call($alice, 'session_get')['export']['hash'];
foreach ([$alice, $bob] as $token) {
    $site->tools->call($token, 'web_export_approve', ['hash' => $hash]);
}
if ('closed' !== $site->tools->call($alice, 'session_get')['state']) {
    SmokeSite::fail('Two approvals did not close the game.');
}

$drush = static function (string ...$arguments): string {
    $process = new Process(['./drupack', 'drush', ...$arguments], dirname(__DIR__, 3).'/drupal', ['DRUPACK_NO_UPDATE_CHECK' => '1'], timeout: 600);
    $process->mustRun();

    return $process->getOutput();
};
$game = $site->seats->game;
$uuid = trim($drush('php:eval', "echo \\Drupal\\node\\Entity\\Node::load($game)->uuid();"));
$verdict = static fn (): array => json_decode($drush('php:eval', "echo json_encode(\\Drupal::keyValue('genie.moderation')->get('$game'));"), true) ?? [];

$drush('genie:moderate');
$score = $verdict()['local'] ?? null;
if (!is_float($score) || $score >= 0.5) {
    SmokeSite::fail('The local model did not pass the final picture: '.json_encode($score));
}
$picture = static fn (string $view): int => $site->http->request('GET', "{$_ENV['PUBLIC_URL']}/games/$uuid/$view")->getStatusCode();
if (200 !== $picture('frame')) {
    SmokeSite::fail('A passed picture left the public page.');
}

// A model can flag a picture only when it shows sexual content, so the test writes the score.
$drush('php:eval', "\\Drupal::keyValue('genie.moderation')->set('$game', ['local' => 0.9]);");
if (404 !== $picture('frame') || 200 !== $picture('photo')) {
    SmokeSite::fail('A withheld picture still shows, or its original photo does not.');
}
$session = $site->tools->call($alice, 'session_get');
if ('The final picture was withheld.' !== ($session['frame']['withheld'] ?? null)) {
    SmokeSite::fail('The player page does not say the picture was withheld.');
}
try {
    $site->frame($alice, $session['revision']);
    SmokeSite::fail('A player still reads the withheld picture.');
} catch (DrupalToolFailed) {
}
$page = $site->http->request('GET', "{$_ENV['PUBLIC_URL']}/games/$uuid")->getContent();
if (!str_contains($page, 'The final picture was withheld.') || !str_contains($page, "/games/$uuid/photo")) {
    SmokeSite::fail('The game page does not say the picture was withheld.');
}
$list = $site->http->request('GET', "{$_ENV['PUBLIC_URL']}/games")->getContent();
if (str_contains($list, "/games/$uuid/frame")) {
    SmokeSite::fail('The game list still shows the withheld picture.');
}
if (!array_key_exists($game, json_decode($drush('genie:flagged'), true))) {
    SmokeSite::fail('genie:flagged does not list the game.');
}
echo "moderation ok\n";
