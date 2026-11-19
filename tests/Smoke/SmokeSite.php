<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Drupal\DrupalPhotos;
use App\Drupal\McpDrupalTools;
use App\Security\DrupalOAuth;
use Mcp\Client\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * What the smoke scripts share: an empty session and signed-in players on the running site.
 */
final readonly class SmokeSite
{
    public string $drupalUrl;
    public HttpClientInterface $http;
    public McpDrupalTools $tools;
    public DrupalPhotos $photos;

    public function __construct()
    {
        $root = \dirname(__DIR__, 2);
        (new Dotenv())->bootEnv($root.'/.env');
        $this->drupalUrl = $_ENV['DRUPAL_URL'];
        $this->http = HttpClient::create();
        $this->tools = new McpDrupalTools($this->drupalUrl.'/mcp');
        $this->photos = new DrupalPhotos($this->http, $this->drupalUrl.'/genie');
        $this->drush('genie:reset');
    }

    /**
     * Reads the shared picture of a revision, as the web view does.
     */
    public function frame(string $token, int $revision): string
    {
        return $this->tools->image($token, 'picture_get', ['view' => 'frame', 'revision' => $revision]);
    }

    /**
     * Runs a Drush command on the site.
     */
    public function drush(string ...$arguments): string
    {
        $process = new Process(['./drupack', 'drush', ...$arguments], \dirname(__DIR__, 2).'/drupal', ['DRUPACK_NO_UPDATE_CHECK' => '1']);

        return $process->mustRun()->getOutput();
    }

    /**
     * Signs in with the web view's client, the only one that reaches the pixels.
     */
    public function signIn(string $player): string
    {
        return (new PlayerSession(
            $this->drupalUrl.'/mcp',
            $this->webCallback(),
            DrupalOAuth::CLIENT_ID,
            $_ENV['GENIE_WEB_SECRET'],
        ))->signIn($player);
    }

    /**
     * Signs in as an assistant that registered the web view's own address.
     */
    public function signInAsAssistant(string $player): string
    {
        return (new PlayerSession($this->drupalUrl.'/mcp', $this->webCallback()))->signIn($player);
    }

    /**
     * Opens a page of the web view in a new browser, which signs in on the way.
     */
    public function openWebPage(string $player, string $path): HttpBrowser
    {
        $browser = new HttpBrowser(HttpClient::create(['timeout' => 60]));
        $browser->request('GET', $_ENV['PUBLIC_URL'].$path);
        for ($step = 0; $step < 5 && !str_starts_with($browser->getHistory()->current()->getUri(), $_ENV['PUBLIC_URL']); ++$step) {
            $page = $browser->getCrawler();
            $login = $page->filter('#user-login-form');
            $browser->submit($login->count() > 0
                ? $login->form(['name' => $player, 'pass' => 'genie'])
                : $page->filter('form input[type=submit]')->first()->form());
        }

        return $browser;
    }

    private function webCallback(): string
    {
        return $_ENV['PUBLIC_URL'].'/oauth/callback';
    }

    public static function fail(string $reason): never
    {
        fwrite(\STDERR, $reason."\n");
        exit(1);
    }

    public function uploadPhoto(string $token): void
    {
        $image = imagecreatetruecolor(2000, 1000);
        ob_start();
        imagejpeg($image);
        $this->photos->put($token, ob_get_clean());
    }

    public function markPlace(string $token, int $x = 10): int
    {
        return $this->tools->call($token, 'claim_place', ['head' => [$x, 350, 100, 100], 'body' => [$x, 350, 100, 100]])['revision'];
    }

    /**
     * Calls a tool as an assistant would, reading the text and the image it returns.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{info: array<string, mixed>, image: ?string}
     */
    public function imageTool(string $token, string $tool, array $arguments): array
    {
        $client = Client::builder()->build();
        $client->connect(new HttpTransport($this->drupalUrl.'/mcp', headers: ['Authorization' => 'Bearer '.$token]));
        $result = $client->callTool($tool, $arguments);
        $client->disconnect();

        $info = [];
        $image = null;
        foreach ($result->content as $content) {
            if ($content instanceof TextContent) {
                $info = json_decode($content->text, true, flags: \JSON_THROW_ON_ERROR);
            } elseif ($content instanceof ImageContent) {
                $image = base64_decode($content->data, true) ?: null;
            }
        }

        return ['info' => $info, 'image' => $image];
    }
}
