<?php

declare(strict_types=1);

namespace Ldkafka\GoogleCloudLogging\Http;

use Ldkafka\GoogleCloudLogging\Exception\GoogleCloudException;

use function curl_close;
use function curl_errno;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function sprintf;
use function strtoupper;

/**
 * curl-based transport with sane timeouts and TLS verification on.
 */
final class CurlHttpClient implements HttpClientInterface
{
    /**
     * @param int $timeout Total request timeout in seconds.
     * @param int $connectTimeout Connection timeout in seconds.
     */
    public function __construct(private int $timeout = 30, private int $connectTimeout = 10)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $handle = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($handle, $options);

        $response = curl_exec($handle);
        if ($response === false) {
            $error = sprintf('HTTP request to %s failed: %s (curl %d)', $url, curl_error($handle), curl_errno($handle));
            curl_close($handle);
            throw new GoogleCloudException($error);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return ['status' => $status, 'body' => (string) $response];
    }
}
