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
for ($wish = 2; $wish <= 10; ++$wish) {
    $site->tools->call($token, 'make_wish', ['wish' => 0 === $wish % 2 ? 'show me' : "wish $wish"]);
}
if (!$refused(['wish' => 'hide me'])) {
    SmokeSite::fail('An eleventh wish in ten minutes was granted.');
}
echo "oversized, blank and eleventh wishes refused\n";
