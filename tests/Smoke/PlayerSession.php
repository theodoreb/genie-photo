<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Signs a demo player in the way an MCP client does: discovery, dynamic
 * registration, then authorization code with PKCE through Drupal's own forms.
 */
final class PlayerSession
{
    private HttpClientInterface $http;

    public function __construct(
        private readonly string $mcpEndpoint,
        private readonly string $redirectUri = 'http://127.0.0.1:9/callback',
        private readonly ?string $clientId = null,
        private readonly ?string $clientSecret = null,
    ) {
        $this->http = HttpClient::create(['timeout' => 60]);
    }

    public function signIn(string $name, string $password = 'genie'): string
    {
        $server = $this->discoverAuthorizationServer();
        $clientId = $this->clientId ?? $this->register($server['registration_endpoint']);
        $verifier = bin2hex(random_bytes(32));
        $code = $this->authorize($server['authorization_endpoint'], $clientId, $verifier, $name, $password);

        return $this->http->request('POST', $server['token_endpoint'], ['body' => [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => $verifier,
        ] + (null === $this->clientSecret ? [] : ['client_secret' => $this->clientSecret])])->toArray()['access_token'];
    }

    /**
     * @return array<string, mixed>
     */
    private function discoverAuthorizationServer(): array
    {
        $challenge = $this->http->request('POST', $this->mcpEndpoint, ['json' => []])->getHeaders(false)['www-authenticate'][0];
        preg_match('/resource_metadata="([^"]+)"/', $challenge, $match);
        $issuer = $this->http->request('GET', $match[1])->toArray()['authorization_servers'][0];

        return $this->http->request('GET', $issuer.'/.well-known/oauth-authorization-server')->toArray();
    }

    private function register(string $endpoint): string
    {
        return $this->http->request('POST', $endpoint, ['json' => [
            'client_name' => 'genie smoke test',
            'redirect_uris' => [$this->redirectUri],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'application_type' => 'native',
        ]])->toArray()['client_id'];
    }

    private function authorize(string $endpoint, string $clientId, string $verifier, string $name, string $password): string
    {
        $state = bin2hex(random_bytes(8));
        $browser = new HttpBrowser(HttpClient::create(['timeout' => 60]));
        $browser->followRedirects(false);

        $browser->request('GET', $endpoint.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $this->redirectUri,
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]));

        for ($step = 0; $step < 10; ++$step) {
            $location = $browser->getInternalResponse()->getHeader('Location');
            if (null !== $location && str_starts_with($location, $this->redirectUri)) {
                parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
                if (!isset($query['code'])) {
                    throw new \RuntimeException('Authorization refused: '.$location);
                }
                if ($query['state'] !== $state) {
                    throw new \RuntimeException('The authorization response carries another state.');
                }

                return $query['code'];
            }
            if (null !== $location) {
                $browser->followRedirect();
                continue;
            }

            $page = $browser->getCrawler();
            if ($page->filter('#user-login-form')->count() > 0) {
                $browser->submit($page->filter('#user-login-form')->form(['name' => $name, 'pass' => $password]));
                continue;
            }
            $browser->submit($page->filter('form input[type=submit]')->first()->form());
        }

        throw new \RuntimeException('Sign-in did not reach the redirect URI. Stopped at '.$browser->getHistory()->current()->getUri().': '.substr(trim(preg_replace('/\s+/', ' ', $browser->getCrawler()->filter('body')->text())), 0, 300));
    }
}
