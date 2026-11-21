#!/usr/bin/env php
<?php

// Critical path of detection: an assistant sees the photo, traces who is in it, claims
// a person and paints on what it can reach. Nobody plays while someone is untraced.

declare(strict_types=1);

use App\Drupal\DrupalToolFailed;
use App\Tests\Smoke\SmokeSite;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$site = new SmokeSite();
$web = $site->signIn('alice');
$site->uploadPhoto($web);
$assistant = $site->signInAsAssistant('alice');

$photo = $site->imageTool($assistant, 'photo_get', ['view' => 'photo']);
if (null === $photo['image'] || false === imagecreatefromstring($photo['image'])) {
    SmokeSite::fail('The assistant received no image.');
}

$refusal = static function (callable $call): string {
    try {
        $call();
    } catch (DrupalToolFailed $e) {
        return $e->getMessage();
    }
    SmokeSite::fail('A call went through while a person was untraced.');
};

$yellow = ['description' => 'yellow sweater', 'head' => [100, 100, 200, 200], 'body' => [50, 100, 300, 700]];
$cake = [['label' => 'cake', 'box' => [700, 500, 200, 200]]];
if (!str_contains($refusal(static fn () => $site->tools->call($assistant, 'scan_photo', ['people' => [$yellow], 'objects' => $cake])), 'Trace yellow sweater')) {
    SmokeSite::fail('A scan without an outline was not refused.');
}
$yellow['outline'] = [[5, 2, 7], [6, 2, 7], [7, 2, 7]];
$site->tools->call($assistant, 'scan_photo', ['people' => [$yellow], 'objects' => $cake]);
$person = $site->imageTool($assistant, 'photo_get', ['view' => 'photo'])['info']['people'][0]['id'];
$site->tools->call($assistant, 'claim_place', ['person' => $person]);

// A box moved by hand has no outline, so every assistant waits for the next trace.
$site->tools->call($assistant, 'claim_place', ['head' => [100, 100, 200, 200], 'body' => [40, 100, 320, 700]]);
if (!str_contains($refusal(static fn () => $site->tools->call($assistant, 'make_wish', ['wish' => 'hide me'])), 'Trace every person')) {
    SmokeSite::fail('The refused wish did not say to trace.');
}
$site->tools->call($assistant, 'scan_photo', ['people' => [$yellow], 'objects' => []]);
$mine = array_filter($site->imageTool($assistant, 'photo_get', ['view' => 'photo'])['info']['people'], static fn (array $one): bool => isset($one['player']));
$person = array_values($mine)[0]['id'];
$site->tools->call($assistant, 'scan_photo', [
    'people' => [
        ['description' => 'yellow sweater again'] + $yellow,
        ['description' => 'green coat', 'head' => [900, 100, 200, 200], 'body' => [850, 100, 300, 700], 'outline' => [[5, 22, 27], [6, 22, 27]]],
    ],
    'objects' => [],
]);
$people = $site->imageTool($assistant, 'photo_get', ['view' => 'photo'])['info']['people'];
$ids = array_column($people, 'id');
if (2 !== count($ids) || !in_array($person, $ids, true)) {
    SmokeSite::fail('A claimed person lost their id after a scan.');
}
$other = array_values(array_diff($ids, [$person]))[0];
$site->tools->call($assistant, 'claim_place', ['person' => $other]);
$offered = $site->tools->call($web, 'session_get')['proposals'];
if ([$person] !== array_column($offered, 'id')) {
    SmokeSite::fail('The person given up did not return to the offers.');
}
$claim = $site->tools->call($assistant, 'claim_place', ['person' => $person]);
$before = $site->frame($web, $claim['revision']);

$painted = $site->imageTool($assistant, 'paint_me', ['palette' => ['#ff00aa'], 'spans' => [[27, 12, 30, '0']]]);
if (null === $painted['image'] || !$painted['info']['granted']) {
    SmokeSite::fail('The paint result did not show the person.');
}
if (hash('sha256', $before) === hash('sha256', $site->frame($web, $painted['info']['revision']))) {
    SmokeSite::fail('The frame did not change.');
}
echo $painted['info']['reply'], "\n";
