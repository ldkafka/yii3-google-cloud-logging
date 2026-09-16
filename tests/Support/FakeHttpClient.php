<?php

declare(strict_types=1);

namespace Ldkafka\GoogleCloudLogging\Tests\Support;

use Ldkafka\GoogleCloudLogging\Exception\GoogleCloudException;
use Ldkafka\GoogleCloudLogging\Http\HttpClientInterface;

use function array_shift;
use function is_array;
use function json_encode;

/**
 * Records requests and replays queued responses.
 */
final class FakeHttpClient implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string|null}> */
    public array $requests = [];

    /** @var list<array{status: int, body: string}> */
    private array $queue = [];

    /**
     * @param array<string, mixed>|string $body Arrays are JSON-encoded.
     */
    public function queue(int $status, array|string $body): self
    {
        $this->queue[] = ['status' => $status, 'body' => is_array($body) ? (string) json_encode($body) : $body];

        return $this;
    }

    /**
     * Queue a successful token exchange.
     */
    public function queueToken(string $token = 'tok', int $expiresIn = 3600): self
    {
        return $this->queue(200, ['access_token' => $token, 'expires_in' => $expiresIn, 'token_type' => 'Bearer']);
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $response = array_shift($this->queue);
        if ($response === null) {
            throw new GoogleCloudException('FakeHttpClient: no queued response for ' . $method . ' ' . $url);
        }

        return $response;
    }

    /**
     * Decoded JSON body of the n-th request (0-based).
     *
     * @return array<string, mixed>
     */
    public function jsonBody(int $index): array
    {
        return (array) json_decode((string) ($this->requests[$index]['body'] ?? ''), true);
    }
}
