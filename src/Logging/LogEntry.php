<?php

declare(strict_types=1);

namespace Ldkafka\GoogleCloudLogging\Logging;

use function is_array;
use function is_scalar;
use function is_string;
use function json_encode;
use function ltrim;
use function preg_match;
use function strrpos;
use function substr;

/**
 * Read helpers over a raw LogEntry array as returned by the API, tolerant of the payload
 * variants (`textPayload`, `jsonPayload`, `protoPayload`) and of the shapes produced by common
 * producers such as the Docker `gcplogs` driver.
 *
 * @see https://cloud.google.com/logging/docs/reference/v2/rest/v2/LogEntry
 */
final class LogEntry
{
    /**
     * @param array<string, mixed> $raw The entry exactly as the API returned it.
     */
    public function __construct(private array $raw)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    public function getInsertId(): string
    {
        return is_string($this->raw['insertId'] ?? null) ? $this->raw['insertId'] : '';
    }

    /**
     * RFC 3339 timestamp (UTC) or empty.
     */
    public function getTimestamp(): string
    {
        return is_string($this->raw['timestamp'] ?? null) ? $this->raw['timestamp'] : '';
    }

    /**
     * Severity name; `DEFAULT` when the producer set none (the gcplogs driver never does).
     */
    public function getSeverity(): string
    {
        return is_string($this->raw['severity'] ?? null) && $this->raw['severity'] !== '' ? $this->raw['severity'] : 'DEFAULT';
    }

    /**
     * Full log name (`projects/x/logs/y`).
     */
    public function getLogName(): string
    {
        return is_string($this->raw['logName'] ?? null) ? $this->raw['logName'] : '';
    }

    /**
     * Log name without the `projects/x/logs/` prefix (URL-encoded parts left as they are).
     */
    public function getShortLogName(): string
    {
        $name = $this->getLogName();
        $slash = strrpos($name, '/');

        return $slash === false ? $name : substr($name, $slash + 1);
    }

    /**
     * Best-effort producer name: the gcplogs container name (without the leading slash), then
     * common labels, then the monitored resource (`type:id`).
     */
    public function getSource(): string
    {
        $payload = $this->raw['jsonPayload'] ?? null;
        if (is_array($payload) && is_array($payload['container'] ?? null) && is_string($payload['container']['name'] ?? null)) {
            return ltrim($payload['container']['name'], '/');
        }
        $labels = $this->raw['labels'] ?? null;
        if (is_array($labels)) {
            foreach (['com.docker.swarm.service.name', 'container.name', 'service', 'app', 'k8s-pod/app'] as $key) {
                if (is_string($labels[$key] ?? null)) {
                    return $labels[$key];
                }
            }
        }
        $resource = $this->raw['resource'] ?? null;
        if (is_array($resource)) {
            $type = is_string($resource['type'] ?? null) ? $resource['type'] : 'unknown';
            $rl = is_array($resource['labels'] ?? null) ? $resource['labels'] : [];
            foreach (['container_name', 'service_name', 'instance_id', 'module_id', 'function_name', 'job_id'] as $key) {
                if (is_string($rl[$key] ?? null)) {
                    return $type . ':' . $rl[$key];
                }
            }

            return $type;
        }

        return 'unknown';
    }

    /**
     * Docker Swarm task names look like `nginx.2.v9k7h44hgf60cpp9f2y74d5ga`; this reduces the
     * source to the service name (`nginx`). Other sources are returned unchanged.
     */
    public function getServiceName(): string
    {
        $source = $this->getSource();

        return preg_match('/^(.+)\.\d+\.[a-z0-9]{20,}$/', $source, $m) === 1 ? $m[1] : $source;
    }

    /**
     * Best-effort message text from the payload variants.
     */
    public function getMessage(): string
    {
        if (is_string($this->raw['textPayload'] ?? null)) {
            return $this->raw['textPayload'];
        }
        $payload = $this->raw['jsonPayload'] ?? null;
        if (is_array($payload)) {
            foreach (['message', 'msg', 'data', 'log', 'text'] as $key) {
                if (isset($payload[$key]) && is_scalar($payload[$key])) {
                    return (string) $payload[$key];
                }
            }
            $rest = $payload;
            unset($rest['container'], $rest['instance']);

            return (string) json_encode($rest, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        $proto = $this->raw['protoPayload'] ?? null;
        if (is_array($proto)) {
            $type = is_string($proto['@type'] ?? null) ? $proto['@type'] : 'protoPayload';
            $method = is_string($proto['methodName'] ?? null) ? ' ' . $proto['methodName'] : '';

            return $type . $method;
        }

        return '';
    }
}
