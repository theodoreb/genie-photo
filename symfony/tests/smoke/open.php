#!/usr/bin/env php
<?php

// Looks at the games as a visitor does, with the web view's own token and on its public pages.
// Checks that this token cannot play, that a player's token cannot list, and that a prepared
// game that ends is replaced while an empty one waits.

declare(strict_types=1);

use App\Drupal\DrupalToolFailed;
use App\Security\DrupalOAuth;
use App\Tests\Smoke\SmokeSite;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$site = new SmokeSite('alice');
$oauth = new DrupalOAuth($site->http, $site->drupalUrl, $_ENV['PUBLIC_URL'], $_ENV['GENIE_WEB_SECRET']);
$visitor = $oauth->visitorToken()['token'];

/**
 * Lists the games a visitor sees, by id.
 *
 * @return array<string, array<string, mixed>>
 */
$listed = static fn (): array => array_column($site->tools->call($visitor, 'web_games_list')['games'], null, 'id');

/**
 * Tells whether Drupal refuses a tool call.
 */
$refused = static function (string $token, string $tool) use ($site): bool {
    try {
        $site->tools->call($token, $tool);
    } catch (DrupalToolFailed) {
        return true;
    }

    return false;
};

$prepared = static fn (array $games): array => array_filter($games, static fn (array $game): bool => null !== $game['credit'] && 'open' === $game['state']);

$alice = $site->signIn('alice');
$site->uploadPhoto($alice);
$uploaded = $site->tools->call($alice, 'session_get')['topic'];

$games = $listed();
$open = $prepared($games);
[$first, $second] = array_keys($open);
$hosted = array_filter($games, static fn (array $game): bool => str_ends_with($uploaded, $game['id']));
$view = $site->tools->call($visitor, 'web_game_view', ['game' => $first]);
$photo = $site->tools->image($visitor, 'web_game_picture', ['game' => $first, 'view' => 'photo']);

$pages = HttpClient::create(['base_uri' => $_ENV['PUBLIC_URL'], 'max_redirects' => 0]);
$home = $pages->request('GET', '/');
$list = $pages->request('GET', '/games');
$page = $pages->request('GET', '/games/'.$first);
$picture = $pages->request('GET', '/games/'.$first.'/photo');

$checks = [
    'six prepared games are open' => 6 === count($open),
    'a prepared game can be joined' => str_contains((string) $open[$first]['join'], '/join/'),
    'an uploaded game is listed without a join address' => 1 === count($hosted) && null === reset($hosted)['join'],
    'a game view names its source' => $first === $view['id'] && '' !== $view['credit']['page'],
    'a game picture is a JPEG' => str_starts_with($photo, "\xFF\xD8"),
    'the home page sends a visitor to the list' => 302 === $home->getStatusCode() && str_ends_with($home->getHeaders(false)['location'][0], '/games'),
    'the list page links the game' => 200 === $list->getStatusCode() && str_contains($list->getContent(false), '/games/'.$first),
    'the game page offers a place' => 200 === $page->getStatusCode() && str_contains($page->getContent(false), $open[$first]['join']),
    'the picture page serves a JPEG' => 'image/jpeg' === ($picture->getHeaders(false)['content-type'][0] ?? ''),
    'the visitor token cannot play' => $refused($visitor, 'session_get') && $refused($visitor, 'my_status'),
    'a player token cannot list' => $refused($alice, 'web_games_list'),
];

// Seats a player in the first prepared game, ages it and an empty one by a day, then runs the idle sweep.
$sweep = <<<'PHP'
    $games = \Drupal::service(\Drupal\genie\Game\Games::class);
    $nodes = \Drupal::entityTypeManager()->getStorage('node');
    $ids = [];
    foreach ($extra as $uuid) {
      $ids[] = $games->byUuid($uuid)->id();
    }
    $games->join((int) $ids[0], 'smoke');
    \Drupal::database()->update('node_field_data')->fields(['changed' => 1])->condition('nid', $ids, 'IN')->execute();
    $nodes->resetCache();
    $games->abandonIdle();
    PHP;
$script = tempnam(sys_get_temp_dir(), 'genie');
file_put_contents($script, "<?php\n".$sweep);
(new Process(['./drupack', 'drush', 'php:script', $script, '--', $first, $second], dirname(__DIR__, 3).'/drupal', ['DRUPACK_NO_UPDATE_CHECK' => '1']))->mustRun();
unlink($script);

$after = $listed();
$checks += [
    'an idle prepared game with a player is abandoned' => 'abandoned' === $after[$first]['state'],
    'an idle prepared game without a player stays open' => 'open' === $after[$second]['state'],
    'another prepared game opened' => 6 === count($prepared($after)),
];

$failed = false;
foreach ($checks as $label => $ok) {
    echo $label, ': ', $ok ? 'ok' : 'FAILED', "\n";
    $failed = $failed || !$ok;
}

exit($failed ? 1 : 0);
