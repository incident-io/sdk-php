<?php

declare(strict_types=1);

namespace IncidentIo\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * A Guzzle client that answers from a queue of canned responses and records
 * every request it sends.
 */
trait MocksHttp
{
    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    private function client(Response ...$responses): Client
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new Client(['handler' => $stack]);
    }

    private function lastRequest(): RequestInterface
    {
        return end($this->history)['request'];
    }

    private static function json(int $status, array $body, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode($body));
    }
}
