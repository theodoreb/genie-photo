<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Drupal\DrupalPhotos;
use App\Drupal\McpDrupalTools;
use App\Security\DrupalOAuth;
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
        (new Process(['./drupack', 'drush', 'genie:reset'], $root.'/drupal', ['DRUPACK_NO_UPDATE_CHECK' => '1']))->mustRun();
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
        return $this->tools->call($token, 'region_put', ['kind' => 'person', 'x' => $x, 'y' => 350, 'width' => 100, 'height' => 100])['revision'];
    }
}
