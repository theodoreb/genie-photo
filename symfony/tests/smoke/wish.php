#!/usr/bin/env php
<?php

// Critical path of a wish: a player marks their place, makes a wish, and the shared frame changes.

declare(strict_types=1);

use App\Drupal\DrupalToolFailed;
use App\Tests\Smoke\SmokeSite;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$player = $argv[1] ?? 'alice';
$wish = $argv[2] ?? 'hide my face';

$site = new SmokeSite();
$token = $site->signIn($player);
$site->uploadPhoto($token);
$before = $site->frame($token, $site->markPlace($token));

$outcome = $site->tools->call($token, 'make_wish', ['wish' => $wish]);
echo $outcome['reply'], "\n";
if (!$outcome['granted']) {
    exit(1);
}

$after = $site->frame($token, $outcome['revision']);
if (hash('sha256', $before) === hash('sha256', $after)) {
    SmokeSite::fail('The frame did not change.');
}
echo "revision {$outcome['revision']}\n";

// Each look stores its value and changes the plain picture.
$plain = $site->frame($token, $site->tools->call($token, 'make_wish', ['wish' => 'show me'])['revision']);
$looks = ['gold' => 'turn me to gold'];
foreach ($looks as $look => $phrase) {
    $granted = $site->tools->call($token, 'make_wish', ['wish' => $phrase]);
    if (!$granted['granted'] || $look !== $site->tools->call($token, 'session_get')['wish']) {
        SmokeSite::fail("The wish \"$phrase\" did not store $look.");
    }
    if (hash('sha256', $plain) === hash('sha256', $site->frame($token, $granted['revision']))) {
        SmokeSite::fail("The look $look did not change the picture.");
    }
}
echo implode(', ', array_keys($looks)), " looks granted\n";

// A wish is capped in size, and each player in pace.
$refused = static function (array $arguments) use ($site, $token): bool {
    try {
        $site->tools->call($token, 'make_wish', $arguments);
    } catch (DrupalToolFailed) {
        return true;
    }

    return false;
};
if (!$refused(['wish' => str_repeat('a', 121)]) || !$refused(['wish' => '   '])) {
    SmokeSite::fail('An oversized or blank wish was granted.');
}
for ($wish = 3 + count($looks); $wish <= 10; ++$wish) {
    $site->tools->call($token, 'make_wish', ['wish' => 0 === $wish % 2 ? 'show me' : "wish $wish"]);
}
if (!$refused(['wish' => 'hide me'])) {
    SmokeSite::fail('An eleventh wish in ten minutes was granted.');
}
echo "oversized, blank and eleventh wishes refused\n";
