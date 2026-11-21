#!/usr/bin/env php
<?php

// Critical path of a whole person: the detector finds people, an assistant traces one, claims it and turns it into pixel art.

declare(strict_types=1);

use App\Tests\Smoke\SmokeSite;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$site = new SmokeSite();
$web = $site->signIn('alice');
$site->photos->put($web, (string) file_get_contents(dirname(__DIR__, 3).'/demo/holiday-party.jpg'));
$assistant = $site->signInAsAssistant('alice');

$deadline = time() + 120;
do {
    $outline = $site->imageTool($assistant, 'photo_get', ['view' => 'outline']);
    $status = $outline['info']['detector'];
    if ('pending' === $status) {
        usleep(500_000);
    }
} while ('pending' === $status && time() < $deadline);
if ('ready' !== $status || [] === $outline['info']['detected']) {
    SmokeSite::fail("The detector ended as $status.");
}
$photo = $site->imageTool($assistant, 'photo_get', ['view' => 'photo'])['info'];
foreach ($outline['info']['detected'] as [$x, $y, $width, $height]) {
    if ($x < 0 || $y < 0 || $x + $width > $photo['width'] || $y + $height > $photo['height']) {
        SmokeSite::fail('A detector box leaves the photo.');
    }
}
echo count($outline['info']['detected'])." people detected\n";

$cell = $outline['info']['cell_pixels'];
$scan = [];
$runs = [];
foreach (array_slice($outline['info']['detected'], 0, 2, true) as $label => [$x, $y, $width, $height]) {
    $runs[$label] = [];
    for ($row = intdiv($y, $cell) + 1; $row < intdiv($y + $height, $cell); ++$row) {
        $runs[$label][] = [$row, intdiv($x, $cell) + 1, intdiv($x + $width, $cell) - 1];
    }
    $scan[] = [
        'description' => "person $label",
        'head' => [$x + intdiv($width, 3), $y, intdiv($width, 3), intdiv($width, 3)],
        'detected' => $label,
        'outline' => $runs[$label],
    ];
}
$site->tools->call($assistant, 'scan_photo', ['people' => $scan]);
$person = $site->imageTool($assistant, 'photo_get', ['view' => 'photo'])['info']['people'][0]['id'];
$claim = $site->tools->call($assistant, 'claim_place', ['person' => $person, 'head' => $scan[0]['head']]);
$before = imagecreatefromstring($site->frame($web, $claim['revision']));

$body = $site->imageTool($assistant, 'photo_get', ['view' => 'my_body']);
if (null === $body['image'] || [] === $body['info']['editable']) {
    SmokeSite::fail('The assistant did not see what it can paint.');
}
$pixelated = $site->imageTool($assistant, 'paint_me', ['pixelate' => true]);
if (!$pixelated['info']['granted'] || null === $pixelated['image']) {
    SmokeSite::fail('The paint result did not show the person.');
}

// Paint the whole photo: only what this player can reach may change.
$fine = intdiv($cell, 5);
$spans = [];
for ($row = 0; $row * $fine < $photo['height']; ++$row) {
    $spans[] = [$row, 0, intdiv($photo['width'] - 1, $fine), '0'];
}
$painted = $site->imageTool($assistant, 'paint_me', ['palette' => ['#ff0000'], 'spans' => $spans]);
$after = imagecreatefromstring($site->frame($web, $painted['info']['revision']));

$inside = static function (int $row, int $column, array $rectangles): bool {
    foreach ($rectangles as [$firstRow, $lastRow, $firstColumn, $lastColumn]) {
        if ($row >= $firstRow && $row <= $lastRow && $column >= $firstColumn && $column <= $lastColumn) {
            return true;
        }
    }

    return false;
};
$ringPainted = false;
foreach ($body['info']['editable'] as [$firstRow, $lastRow, $firstColumn, $lastColumn]) {
    for ($row = $firstRow; $row < $lastRow; $row += 5) {
        for ($column = $firstColumn; $column < $lastColumn; $column += 5) {
            $ringPainted = $ringPainted || (!$inside($row, $column, $body['info']['on_person'])
                && 0xFF0000 === imagecolorat($after, $column * $fine + 20, $row * $fine + 20));
        }
    }
}
if (!$ringPainted) {
    SmokeSite::fail('Paint did not reach the safe zone.');
}
$second = array_values($runs)[1];
$neighbour = null;
foreach ($second as [$row, $firstColumn, $lastColumn]) {
    foreach (range($firstColumn, $lastColumn) as $column) {
        if (!$inside($row * 5, $column * 5, $body['info']['editable'])) {
            $neighbour ??= [$column * $cell + 20, $row * $cell + 20];
        }
    }
}
if (null === $neighbour || imagecolorat($before, ...$neighbour) !== imagecolorat($after, ...$neighbour)) {
    SmokeSite::fail('Paint reached another person.');
}
echo "revision {$painted['info']['revision']}\n";
