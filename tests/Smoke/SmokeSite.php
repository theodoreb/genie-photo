<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Drupal\DrupalPhotos;
use App\Drupal\McpDrupalTools;
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
    private PlayerSession $players;

    public function __construct()
    {
        $root = \dirname(__DIR__, 2);
        (new Dotenv())->bootEnv($root.'/.env');
        $this->drupalUrl = $_ENV['DRUPAL_URL'];
        $this->http = HttpClient::create();
        $this->tools = new McpDrupalTools($this->drupalUrl.'/mcp');
        $this->photos = new DrupalPhotos($this->http, $this->drupalUrl.'/genie');
        $this->players = new PlayerSession($this->drupalUrl.'/mcp');
        (new Process(['./drupack', 'drush', 'genie:reset'], $root.'/drupal', ['DRUPACK_NO_UPDATE_CHECK' => '1']))->mustRun();
    }

    public function signIn(string $player): string
    {
        return $this->players->signIn($player);
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
        return $this->tools->call($token, 'region_put', ['kind' => 'person', 'x' => $x, 'y' => 10, 'width' => 100, 'height' => 100])['revision'];
    }
}
