<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that counts the requests it sends.
 */
final class CountingHttpClient implements ClientInterface
{
    public int $sent = 0;

    public function __construct(private readonly ClientInterface $inner)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        ++$this->sent;

        return $this->inner->sendRequest($request);
    }
}
