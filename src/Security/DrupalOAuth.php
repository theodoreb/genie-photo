<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The web view's OAuth client of Drupal: registration, PKCE and token calls.
 */
final readonly class DrupalOAuth
{
    public function __construct(
        private HttpClientInterface $http,
        #[Autowire('%env(DRUPAL_URL)%')]
        private string $drupalUrl,
        #[Autowire('%env(PUBLIC_URL)%')]
        private string $publicUrl,
    ) {
    }

    /**
     * Registers a client and builds the address that starts the sign-in.
     *
     * A new client per sign-in survives a reinstall of the Drupal site.
     *
     * @return array{url: string, clientId: string, state: string, verifier: string}
     */
    public function start(): array
    {
        $server = $this->server();
        $clientId = $this->http->request('POST', $server['registration_endpoint'], ['json' => [
            'client_name' => 'Genie web view',
            'redirect_uris' => [$this->redirectUri()],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ]])->toArray()['client_id'];

        $state = bin2hex(random_bytes(16));
        $verifier = bin2hex(random_bytes(32));

        return [
            'url' => $server['authorization_endpoint'].'?'.http_build_query([
                'response_type' => 'code',
                'client_id' => $clientId,
                'redirect_uri' => $this->redirectUri(),
                'state' => $state,
                'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
                'code_challenge_method' => 'S256',
            ]),
            'clientId' => $clientId,
            'state' => $state,
            'verifier' => $verifier,
        ];
    }

    public function exchange(string $clientId, string $code, string $verifier): TokenSet
    {
        return $this->tokens($clientId, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'code_verifier' => $verifier,
        ]);
    }

    public function refresh(TokenSet $tokens): TokenSet
    {
        return $this->tokens($tokens->clientId, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens->refreshToken,
        ]);
    }

    /**
     * @param array<string, string> $grant
     */
    private function tokens(string $clientId, array $grant): TokenSet
    {
        $body = $this->http->request('POST', $this->server()['token_endpoint'], [
            'body' => ['client_id' => $clientId] + $grant,
        ])->toArray();

        return new TokenSet($clientId, $body['access_token'], $body['refresh_token'], time() + $body['expires_in']);
    }

    /**
     * @return array<string, mixed>
     */
    private function server(): array
    {
        return $this->http->request('GET', $this->drupalUrl.'/.well-known/oauth-authorization-server')->toArray();
    }

    private function redirectUri(): string
    {
        return $this->publicUrl.'/oauth/callback';
    }
}
