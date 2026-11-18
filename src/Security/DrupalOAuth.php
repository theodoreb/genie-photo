<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The web view's confidential OAuth client of Drupal: PKCE and token calls.
 */
final readonly class DrupalOAuth
{
    public const CLIENT_ID = 'genie-web';

    public function __construct(
        private HttpClientInterface $http,
        #[Autowire('%env(DRUPAL_URL)%')]
        private string $drupalUrl,
        #[Autowire('%env(PUBLIC_URL)%')]
        private string $publicUrl,
        #[Autowire('%env(GENIE_WEB_SECRET)%')]
        private string $clientSecret,
    ) {
    }

    /**
     * Builds the address that starts the sign-in.
     *
     * @return array{url: string, state: string, verifier: string}
     */
    public function start(): array
    {
        $server = $this->server();
        $state = bin2hex(random_bytes(16));
        $verifier = bin2hex(random_bytes(32));

        return [
            'url' => $server['authorization_endpoint'].'?'.http_build_query([
                'response_type' => 'code',
                'client_id' => self::CLIENT_ID,
                'redirect_uri' => $this->redirectUri(),
                'state' => $state,
                'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
                'code_challenge_method' => 'S256',
            ]),
            'state' => $state,
            'verifier' => $verifier,
        ];
    }

    public function exchange(string $code, string $verifier): TokenSet
    {
        return $this->tokens([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'code_verifier' => $verifier,
        ]);
    }

    public function refresh(TokenSet $tokens): TokenSet
    {
        return $this->tokens([
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens->refreshToken,
        ]);
    }

    /**
     * @param array<string, string> $grant
     */
    private function tokens(array $grant): TokenSet
    {
        $body = $this->http->request('POST', $this->server()['token_endpoint'], [
            'body' => ['client_id' => self::CLIENT_ID, 'client_secret' => $this->clientSecret] + $grant,
        ])->toArray();

        return new TokenSet($body['access_token'], $body['refresh_token'], time() + $body['expires_in']);
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
