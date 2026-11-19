#!/usr/bin/env php
<?php

// Critical path of the approval page: the link survives sign-in, only the named player
// gets the button, the page approves, and a later change makes the link stale.

declare(strict_types=1);

use App\Tests\Smoke\SmokeSite;
use Symfony\Component\BrowserKit\HttpBrowser;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$site = new SmokeSite();
$alice = $site->signIn('alice');
$bob = $site->signIn('bob');
$site->uploadPhoto($alice);
$site->markPlace($alice, 100);
$site->markPlace($bob, 800);

$session = $site->tools->call($alice, 'session_get');
$link = "/review/{$session['revision']}/{$session['export']['hash']}?for=alice";
$state = static fn (HttpBrowser $browser): string => $browser->getCrawler()->filter('#review')->attr('data-state');

$browser = $site->openWebPage('alice', $link);
if (!str_ends_with($browser->getHistory()->current()->getUri(), $link)) {
    SmokeSite::fail('The sign-in did not return to the approval link.');
}
if ('ready' !== $state($browser)) {
    SmokeSite::fail('The named player did not get the approve button.');
}
if ('other_player' !== $state($site->openWebPage('bob', $link))) {
    SmokeSite::fail('Another player reached the approve button.');
}

$browser->submit($browser->getCrawler()->filter('#approve')->form());
if (!$site->tools->call($alice, 'session_get')['export']['approved_by_you']) {
    SmokeSite::fail('The page did not record the approval.');
}
$browser->request('GET', $_ENV['PUBLIC_URL'].$link);
if ('approved' !== $state($browser)) {
    SmokeSite::fail('The page did not show the approval.');
}

$site->tools->call($bob, 'make_wish', ['wish' => 'hide my face']);
$browser->request('GET', $_ENV['PUBLIC_URL'].$link);
if ('changed' !== $state($browser) || $browser->getCrawler()->filter('#approve')->count() > 0) {
    SmokeSite::fail('A stale link still offers approval.');
}
echo "review page ok\n";
