<?php

declare(strict_types=1);

namespace Ldkafka\GoogleCloudLogging\Logging;

use Generator;
use Ldkafka\GoogleCloudLogging\Auth\ServiceAccountCredentials;
use Ldkafka\GoogleCloudLogging\Exception\ApiException;
use Ldkafka\GoogleCloudLogging\Exception\GoogleCloudException;
use Ldkafka\GoogleCloudLogging\Http\HttpClientInterface;

use function array_map;
use function count;
use function http_build_query;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function min;
use function rawurlencode;
use function sprintf;
use function str_contains;
use function substr;

/**
 * Client for the Cloud Logging REST API v2: list the logs of a project, read entries, write
 * entries.
 *
 * @see https://cloud.google.com/logging/docs/reference/v2/rest
 */
final class CloudLoggingClient
{
    public const BASE_URL = 'https://logging.googleapis.com/v2';
    public const MAX_PAGE_SIZE = 1000;

    private string $projectId;

    /**
     * @param ServiceAccountCredentials $credentials Token source.
     * @param HttpClientInterface $http Transport.
     * @param string|null $projectId Project whose logs are addressed; defaults to the key's `project_id`.
     */
    public function __construct(
        private ServiceAccountCredentials $credentials,
        private HttpClientInterface $http,
        ?string $projectId = null,
    ) {
        $this->projectId = $projectId ?? '';
    }

    /**
     * Project id in use (resolved from the key when none was given).
     *
     * @throws GoogleCloudException When neither a project id nor a readable key is available.
     */
    public function getProjectId(): string
    {
        if ($this->projectId === '') {
            $fromKey = $this->credentials->getProjectId();
            if ($fromKey === null) {
                throw new GoogleCloudException('No project id configured and the key file carries none.');
            }
            $this->projectId = $fromKey;
        }

        return $this->projectId;
    }

    public function getCredentials(): ServiceAccountCredentials
    {
        return $this->credentials;
    }

    /**
     * Names of the logs that have entries in the project.
     *
     * @return array{logNames: list<string>, nextPageToken: string|null}
     * @throws GoogleCloudException
     */
    public function listLogs(int $pageSize = 200, ?string $pageToken = null): array
    {
        $query = ['pageSize' => max(1, min(1000, $pageSize))];
        if ($pageToken !== null && $pageToken !== '') {
            $query['pageToken'] = $pageToken;
        }
        $url = sprintf('%s/projects/%s/logs?%s', self::BASE_URL, rawurlencode($this->getProjectId()), http_build_query($query));
        $data = $this->call('GET', $url);

        $names = [];
        foreach ($data['logNames'] ?? [] as $name) {
            if (is_string($name)) {
                $names[] = $name;
            }
        }

        return ['logNames' => $names, 'nextPageToken' => self::token($data)];
    }

    /**
     * Read one page of entries matching a filter.
     *
     * @param string|LogFilterBuilder $filter Logging query-language filter; empty for everything.
     * @param int $pageSize 1..1000.
     * @param bool $newestFirst Order by timestamp descending (true) or ascending.
     * @param string|null $pageToken Continuation token from a previous call.
     * @param list<string>|null $resourceNames Override the resources to search (default: the project).
     * @return array{entries: list<LogEntry>, nextPageToken: string|null}
     * @throws GoogleCloudException
     */
    public function listEntries(string|LogFilterBuilder $filter = '', int $pageSize = 100, bool $newestFirst = true, ?string $pageToken = null, ?array $resourceNames = null): array
    {
        $body = [
            'resourceNames' => $resourceNames ?? ['projects/' . $this->getProjectId()],
            'orderBy' => $newestFirst ? 'timestamp desc' : 'timestamp asc',
            'pageSize' => max(1, min(self::MAX_PAGE_SIZE, $pageSize)),
        ];
        $filter = (string) $filter;
        if ($filter !== '') {
            $body['filter'] = $filter;
        }
        if ($pageToken !== null && $pageToken !== '') {
            $body['pageToken'] = $pageToken;
        }

        $data = $this->call('POST', self::BASE_URL . '/entries:list', $body);

        $entries = [];
        foreach ($data['entries'] ?? [] as $entry) {
            if (is_array($entry)) {
                $entries[] = new LogEntry($entry);
            }
        }

        return ['entries' => $entries, 'nextPageToken' => self::token($data)];
    }

    /**
     * Iterate over entries across pages, stopping after $maxEntries.
     *
     * @return Generator<int, LogEntry>
     * @throws GoogleCloudException
     */
    public function iterateEntries(string|LogFilterBuilder $filter = '', int $maxEntries = 1000, bool $newestFirst = true, int $pageSize = 500): Generator
    {
        $token = null;
        $yielded = 0;
        do {
            $page = $this->listEntries($filter, min($pageSize, $maxEntries - $yielded), $newestFirst, $token);
            foreach ($page['entries'] as $entry) {
                yield $entry;
                if (++$yielded >= $maxEntries) {
                    return;
                }
            }
            $token = $page['nextPageToken'];
        } while ($token !== null && $page['entries'] !== []);
    }

    /**
     * Write entries to a log (`entries:write`). Needs the `logging.write` scope.
     *
     * Each entry is an array with at least `textPayload` or `jsonPayload`; `severity`,
     * `timestamp`, `labels`, `logName` and `resource` are optional per entry and default to the
     * arguments given here.
     *
     * @param list<array<string, mixed>> $entries
     * @param string $logName Short (`my-app`) or full (`projects/x/logs/my-app`) log name.
     * @param array<string, mixed> $resource Monitored resource; `{"type":"global"}` works everywhere.
     * @param array<string, string> $labels Labels applied to every entry.
     * @throws GoogleCloudException
     */
    public function writeEntries(array $entries, string $logName, array $resource = ['type' => 'global'], array $labels = []): void
    {
        if ($entries === []) {
            return;
        }
        if (!str_contains($logName, '/logs/')) {
            $logName = sprintf('projects/%s/logs/%s', $this->getProjectId(), $logName);
        }
        $body = [
            'logName' => $logName,
            'resource' => $resource,
            'entries' => array_map(static function (array $entry): array {
                if (!isset($entry['textPayload']) && !isset($entry['jsonPayload'])) {
                    $entry['textPayload'] = '';
                }

                return $entry;
            }, $entries),
            'partialSuccess' => true,
        ];
        if ($labels !== []) {
            $body['labels'] = $labels;
        }

        $this->call('POST', self::BASE_URL . '/entries:write', $body);
    }

    /**
     * @param array<string, mixed>|null $json Request body for POST.
     * @return array<string, mixed> Decoded response.
     * @throws GoogleCloudException
     */
    private function call(string $method, string $url, ?array $json = null): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->credentials->getAccessToken(),
            'Accept' => 'application/json',
        ];
        $body = null;
        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = (string) json_encode($json, JSON_UNESCAPED_SLASHES);
        }

        $response = $this->http->request($method, $url, $headers, $body);
        $data = json_decode($response['body'], true);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $message = is_array($data) && isset($data['error']['message']) && is_string($data['error']['message'])
                ? $data['error']['message']
                : substr($response['body'], 0, 300);
            $status = is_array($data) && isset($data['error']['status']) && is_string($data['error']['status']) ? $data['error']['status'] : null;
            throw new ApiException(sprintf('Cloud Logging API error (HTTP %d): %s', $response['status'], $message), $response['status'], $status);
        }
        if (!is_array($data)) {
            return [];
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function token(array $data): ?string
    {
        $token = $data['nextPageToken'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }
}
