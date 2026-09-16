<?php

declare(strict_types=1);

namespace Ldkafka\GoogleCloudLogging\Mcp;

use InvalidArgumentException;
use Ldkafka\GoogleCloudLogging\Exception\GoogleCloudException;
use Ldkafka\GoogleCloudLogging\Logging\CloudLoggingClient;
use Ldkafka\GoogleCloudLogging\Logging\LogEntry;
use Ldkafka\GoogleCloudLogging\Logging\LogFilterBuilder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use YiiMcp\McpServer\Contract\McpToolAnnotationsInterface;
use YiiMcp\McpServer\Contract\McpToolInterface;

use function array_keys;
use function arsort;
use function count;
use function implode;
use function is_string;
use function json_encode;
use function max;
use function mb_strimwidth;
use function min;
use function sprintf;
use function str_replace;
use function trim;
use function uasort;

/**
 * MCP tool over Cloud Logging for AI assistants (requires `ldkafka/yii3-mcp-server`).
 *
 * Actions: `status` (configuration and connectivity), `list_logs`, `services` (who logged
 * recently, grouped by producer with counts per severity, sampled from the newest entries),
 * `read` (entries filtered by log, container, severity, time window and text; newest first;
 * paginated; `format` text or json). Read-only: the service account needs `roles/logging.viewer`.
 *
 * The tool name and the descriptive texts are configurable so one package serves any project.
 */
final class CloudLoggingTool implements McpToolInterface, McpToolAnnotationsInterface
{
    public const DEFAULT_LIMIT = 100;
    public const MAX_LIMIT = 500;
    public const DEFAULT_SINCE = '24h';
    public const SAMPLE_SIZE = 1000;

    private LoggerInterface $logger;

    /**
     * @param CloudLoggingClient $client Logging API client.
     * @param string $name Tool name advertised to the assistant.
     * @param string $description Extra sentence(s) describing what these logs contain in your setup.
     * @param int $maxMessageChars Truncate each message in text output to this many characters.
     */
    public function __construct(
        private CloudLoggingClient $client,
        private string $name = 'cloud_logs',
        private string $description = '',
        private int $maxMessageChars = 400,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        $base = 'Read Google Cloud Logging entries. Actions: status (connectivity), list_logs (log names), '
            . 'services (who logged recently, counts by severity), read (entries filtered by log/service/severity/time/text, '
            . 'newest first, paginated; format text or json). Times accept RFC 3339 or relative values like 15m, 2h, 3d.';

        return trim($this->description) === '' ? $base : trim($this->description) . ' ' . $base;
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['status', 'list_logs', 'services', 'read'], 'description' => 'Operation to perform.'],
                'log' => ['type' => 'string', 'description' => 'Log name, short (gcplogs-docker-driver) or full (projects/x/logs/y). read/services.'],
                'service' => ['type' => 'string', 'description' => 'Container/service name substring (jsonPayload.container.name). read.'],
                'exclude' => ['type' => 'string', 'description' => 'Container/service name substring to drop, e.g. a noisy proxy. read/services.'],
                'resource_type' => ['type' => 'string', 'description' => 'Monitored resource type (gce_instance, cloud_run_revision, global, ...). read/services.'],
                'severity' => ['type' => 'string', 'enum' => LogFilterBuilder::SEVERITIES, 'description' => 'Minimum severity (inclusive). Producers such as the Docker gcplogs driver set none; use contains instead. read/services.'],
                'since' => ['type' => 'string', 'description' => 'Window start: RFC 3339, "YYYY-MM-DD HH:MM" (UTC), Unix timestamp, or relative (15m, 2h, 3d). Default 24h. read/services.'],
                'until' => ['type' => 'string', 'description' => 'Window end, same formats. read.'],
                'contains' => ['type' => 'string', 'description' => 'Substring the message must contain. read.'],
                'filter' => ['type' => 'string', 'description' => 'Extra raw Logging query-language clause, ANDed with the others. read/services.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'default' => self::DEFAULT_LIMIT, 'description' => 'Maximum entries to return. read.'],
                'page_token' => ['type' => 'string', 'description' => 'Continuation token from a previous read.'],
                'order' => ['type' => 'string', 'enum' => ['desc', 'asc'], 'default' => 'desc', 'description' => 'desc = newest first (default).'],
                'format' => ['type' => 'string', 'enum' => ['text', 'json'], 'default' => 'text', 'description' => 'text = one compact line per entry; json = raw LogEntry objects.'],
            ],
            'required' => ['action'],
            'additionalProperties' => false,
        ];
    }

    public function getAnnotations(): array
    {
        return ['title' => 'Google Cloud Logging', 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => true];
    }

    public function execute(array $args): array
    {
        $action = is_string($args['action'] ?? null) ? $args['action'] : '';
        try {
            return match ($action) {
                'status' => $this->status(),
                'list_logs' => $this->listLogs($args),
                'services' => $this->services($args),
                'read' => $this->read($args),
                default => $this->error('Unknown action "' . $action . '". Use status, list_logs, services or read.'),
            };
        } catch (GoogleCloudException | InvalidArgumentException $e) {
            $this->logger->warning('{tool} {action} failed: {error}', ['tool' => $this->name, 'action' => $action, 'error' => $e->getMessage()]);

            return $this->error($e->getMessage());
        } catch (Throwable $e) {
            $this->logger->error('{tool} {action} crashed: {error}', ['tool' => $this->name, 'action' => $action, 'error' => $e->getMessage(), 'exception' => $e]);

            return $this->error('Unexpected failure: ' . $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function status(): array
    {
        $credentials = $this->client->getCredentials();
        $lines = ['Key file: ' . ($credentials->getKeyFile() === '' ? '(inline key)' : $credentials->getKeyFile())];
        if (!$credentials->isConfigured()) {
            $lines[] = 'Key: MISSING or unreadable. Provide the service-account JSON key (file path or GOOGLE_APPLICATION_CREDENTIALS).';

            return $this->error(implode("\n", $lines));
        }
        $lines[] = 'Project: ' . $this->client->getProjectId();
        $lines[] = 'Service account: ' . $credentials->getClientEmail();
        $lines[] = 'Scope: ' . $credentials->getScope();
        $credentials->getAccessToken();
        $lines[] = 'Token exchange: OK';
        $logs = $this->client->listLogs(5);
        $lines[] = sprintf('Logs API: OK (%d log name(s) in first page%s)', count($logs['logNames']), $logs['nextPageToken'] !== null ? ', more available' : '');

        return $this->text(implode("\n", $lines));
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function listLogs(array $args): array
    {
        $result = $this->client->listLogs(200, self::str($args, 'page_token'));
        $lines = [sprintf('%d log(s) in project %s:', count($result['logNames']), $this->client->getProjectId())];
        foreach ($result['logNames'] as $name) {
            $lines[] = '  ' . $name;
        }
        if ($result['nextPageToken'] !== null) {
            $lines[] = 'next page_token: ' . $result['nextPageToken'];
        }

        return $this->text(implode("\n", $lines));
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function services(array $args): array
    {
        $since = self::str($args, 'since') ?? self::DEFAULT_SINCE;
        $filter = (new LogFilterBuilder($this->client->getProjectId()))
            ->log(self::str($args, 'log') ?? '')
            ->resourceType(self::str($args, 'resource_type') ?? '')
            ->excludeContainerName(self::str($args, 'exclude') ?? '')
            ->minSeverity(self::str($args, 'severity') ?? '')
            ->since($since)
            ->raw(self::str($args, 'filter') ?? '');
        $result = $this->client->listEntries($filter, self::SAMPLE_SIZE, true);

        $byService = [];
        $oldest = null;
        foreach ($result['entries'] as $entry) {
            $service = $entry->getServiceName();
            $severity = $entry->getSeverity();
            $ts = $entry->getTimestamp();
            $byService[$service] ??= ['count' => 0, 'severities' => [], 'newest' => $ts, 'logs' => [], 'tasks' => []];
            $byService[$service]['count']++;
            $byService[$service]['severities'][$severity] = ($byService[$service]['severities'][$severity] ?? 0) + 1;
            $byService[$service]['logs'][$entry->getShortLogName()] = true;
            $byService[$service]['tasks'][$entry->getSource()] = true;
            $oldest = $ts;
        }
        uasort($byService, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        $lines = [sprintf(
            'Sampled %d newest entries since %s%s; sample reaches back to %s (%d service(s)):',
            count($result['entries']),
            $since,
            $result['nextPageToken'] !== null ? ', more exist' : '',
            $oldest ?? '-',
            count($byService)
        )];
        foreach ($byService as $service => $info) {
            arsort($info['severities']);
            $sev = [];
            foreach ($info['severities'] as $name => $n) {
                $sev[] = $name . '=' . $n;
            }
            $lines[] = sprintf('  %-28s %5d entries  %d task(s)  [%s]  logs: %s  newest: %s', $service, $info['count'], count($info['tasks']), implode(' ', $sev), implode(',', array_keys($info['logs'])), $info['newest']);
        }
        if ($byService === []) {
            $lines[] = '  (no entries; widen "since", drop "severity", or check that the producer is shipping)';
        } else {
            $lines[] = 'Tip: chatty producers (a reverse proxy) dominate the sample; pass exclude=<name> or service=<name> to focus.';
        }

        return $this->text(implode("\n", $lines));
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function read(array $args): array
    {
        $limit = max(1, min(self::MAX_LIMIT, (int) ($args['limit'] ?? self::DEFAULT_LIMIT)));
        $format = self::str($args, 'format') ?? 'text';
        $newestFirst = (self::str($args, 'order') ?? 'desc') !== 'asc';

        $filter = (new LogFilterBuilder($this->client->getProjectId()))
            ->log(self::str($args, 'log') ?? '')
            ->resourceType(self::str($args, 'resource_type') ?? '')
            ->containerName(self::str($args, 'service') ?? '')
            ->excludeContainerName(self::str($args, 'exclude') ?? '')
            ->minSeverity(self::str($args, 'severity') ?? '')
            ->since(self::str($args, 'since') ?? self::DEFAULT_SINCE)
            ->until(self::str($args, 'until') ?? '')
            ->contains(self::str($args, 'contains') ?? '')
            ->raw(self::str($args, 'filter') ?? '')
            ->build();

        $result = $this->client->listEntries($filter, $limit, $newestFirst, self::str($args, 'page_token'));

        if ($format === 'json') {
            $payload = [
                'filter' => $filter,
                'count' => count($result['entries']),
                'nextPageToken' => $result['nextPageToken'],
                'entries' => array_map(static fn (LogEntry $e): array => $e->toArray(), $result['entries']),
            ];

            return $this->text((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        }

        $lines = [sprintf('filter: %s', $filter === '' ? '(none)' : $filter), sprintf('%d entrie(s), %s first:', count($result['entries']), $newestFirst ? 'newest' : 'oldest')];
        foreach ($result['entries'] as $entry) {
            $lines[] = $this->formatLine($entry);
        }
        if ($result['nextPageToken'] !== null) {
            $lines[] = 'next page_token: ' . $result['nextPageToken'];
        }

        return $this->text(implode("\n", $lines));
    }

    private function formatLine(LogEntry $entry): string
    {
        $message = str_replace(["\r\n", "\n"], ' | ', trim($entry->getMessage()));
        $message = mb_strimwidth($message, 0, $this->maxMessageChars, '…');

        return sprintf('%s %-8s [%s] %s', $entry->getTimestamp() !== '' ? $entry->getTimestamp() : '-', $entry->getSeverity(), $entry->getSource(), $message);
    }

    /** @param array<string, mixed> $args */
    private static function str(array $args, string $key): ?string
    {
        $value = $args[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** @return array{content: list<array{type: string, text: string}>} */
    private function text(string $text): array
    {
        return ['content' => [['type' => 'text', 'text' => $text]]];
    }

    /** @return array{isError: bool, content: list<array{type: string, text: string}>} */
    private function error(string $message): array
    {
        return ['isError' => true, 'content' => [['type' => 'text', 'text' => $message]]];
    }
}
