<?php

declare(strict_types=1);

namespace Ldkafka\GoogleCloudLogging\Http;

use Ldkafka\GoogleCloudLogging\Exception\GoogleCloudException;

/**
 * Minimal HTTP transport used for the token exchange and the Logging REST calls, so both can be
 * exercised in tests with a fake, and so applications can plug in their own client.
 */
interface HttpClientInterface
{
    /**
     * Perform one HTTP request.
     *
     * @param string $method GET, POST, ...
     * @param string $url Absolute URL.
     * @param array<string, string> $headers Header name => value.
     * @param string|null $body Raw request body (already encoded), or null for none.
     * @return array{status: int, body: string} Status code and raw response body.
     * @throws GoogleCloudException On transport-level failure (DNS, TLS, timeout).
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array;
}
