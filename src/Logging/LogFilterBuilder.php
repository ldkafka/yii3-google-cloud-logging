<?php

declare(strict_types=1);

namespace Ldkafka\GoogleCloudLogging\Logging;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

use function addcslashes;
use function implode;
use function in_array;
use function is_numeric;
use function preg_match;
use function sprintf;
use function str_contains;
use function strtolower;
use function strtoupper;
use function trim;

/**
 * Builds Cloud Logging query-language filters from structured arguments, with proper quoting.
 *
 * Every clause is ANDed. Time bounds accept RFC 3339 strings, "YYYY-MM-DD HH:MM" (UTC), Unix
 * timestamps, DateTimeInterface objects, or relative durations such as `15m`, `2h`, `3d`, `1w`.
 *
 * @see https://cloud.google.com/logging/docs/view/logging-query-language
 */
final class LogFilterBuilder
{
    public const SEVERITIES = ['DEFAULT', 'DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    /** @var list<string> */
    private array $clauses = [];

    /**
     * @param string $projectId Used to expand short log names into `projects/<id>/logs/<name>`.
     */
    public function __construct(private string $projectId)
    {
    }

    /**
     * Restrict to one log. Accepts a short name (`gcplogs-docker-driver`) or a full resource name.
     */
    public function log(string $log): self
    {
        $log = trim($log);
        if ($log === '') {
            return $this;
        }
        $name = str_contains($log, '/logs/') ? $log : sprintf('projects/%s/logs/%s', $this->projectId, $log);
        $this->clauses[] = 'logName = ' . self::quote($name);

        return $this;
    }

    /**
     * Restrict to a monitored resource type (`gce_instance`, `cloud_run_revision`, `global`, ...).
     */
    public function resourceType(string $type): self
    {
        $type = trim($type);
        if ($type !== '') {
            $this->clauses[] = 'resource.type = ' . self::quote($type);
        }

        return $this;
    }

    /**
     * Entries produced by a Docker container whose name contains the given text. The Docker
     * `gcplogs` driver records it in `jsonPayload.container.name`.
     */
    public function containerName(string $name): self
    {
        $name = trim($name);
        if ($name !== '') {
            $this->clauses[] = 'jsonPayload.container.name : ' . self::quote($name);
        }

        return $this;
    }

    /**
     * Drop entries produced by containers whose name contains the given text.
     */
    public function excludeContainerName(string $name): self
    {
        $name = trim($name);
        if ($name !== '') {
            $this->clauses[] = 'NOT jsonPayload.container.name : ' . self::quote($name);
        }

        return $this;
    }

    /**
     * Restrict by a user label (`labels."key" = "value"`).
     */
    public function label(string $key, string $value): self
    {
        $this->clauses[] = sprintf('labels.%s = %s', self::quote($key), self::quote($value));

        return $this;
    }

    /**
     * Generic comparison on any field: `field($name, '=', $value)`, `field($name, ':', $substr)`,
     * `field($name, '>=', $number)`. Values are quoted unless numeric.
     */
    public function field(string $name, string $operator, string|int|float|bool $value): self
    {
        if (!in_array($operator, ['=', '!=', ':', '=~', '!~', '<', '<=', '>', '>='], true)) {
            throw new InvalidArgumentException('Unsupported operator "' . $operator . '".');
        }
        $literal = match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            default => self::quote($value),
        };
        $this->clauses[] = sprintf('%s %s %s', trim($name), $operator, $literal);

        return $this;
    }

    /**
     * Minimum severity (inclusive).
     */
    public function minSeverity(string $severity): self
    {
        $severity = strtoupper(trim($severity));
        if ($severity === '') {
            return $this;
        }
        if (!in_array($severity, self::SEVERITIES, true)) {
            throw new InvalidArgumentException('Unknown severity "' . $severity . '". Use one of: ' . implode(', ', self::SEVERITIES));
        }
        $this->clauses[] = 'severity >= ' . $severity;

        return $this;
    }

    /**
     * Entries at or after the given time.
     */
    public function since(string|int|DateTimeInterface $time, ?DateTimeImmutable $now = null): self
    {
        if (!(is_string($time) && trim($time) === '')) {
            $this->clauses[] = 'timestamp >= ' . self::quote(self::toRfc3339($time, $now));
        }

        return $this;
    }

    /**
     * Entries at or before the given time.
     */
    public function until(string|int|DateTimeInterface $time, ?DateTimeImmutable $now = null): self
    {
        if (!(is_string($time) && trim($time) === '')) {
            $this->clauses[] = 'timestamp <= ' . self::quote(self::toRfc3339($time, $now));
        }

        return $this;
    }

    /**
     * Substring search over the message fields used by containers and plain-text logs.
     */
    public function contains(string $text): self
    {
        $text = trim($text);
        if ($text !== '') {
            $q = self::quote($text);
            $this->clauses[] = sprintf('(textPayload : %s OR jsonPayload.message : %s OR jsonPayload.data : %s)', $q, $q, $q);
        }

        return $this;
    }

    /**
     * Raw clause in Logging query language, ANDed as-is (advanced use).
     */
    public function raw(string $clause): self
    {
        $clause = trim($clause);
        if ($clause !== '') {
            $this->clauses[] = '(' . $clause . ')';
        }

        return $this;
    }

    /**
     * The assembled filter; empty string when nothing was restricted.
     */
    public function build(): string
    {
        return implode(' AND ', $this->clauses);
    }

    public function __toString(): string
    {
        return $this->build();
    }

    /**
     * Quote a string literal for the query language.
     */
    public static function quote(string $value): string
    {
        return '"' . addcslashes($value, "\"\\") . '"';
    }

    /**
     * Convert an absolute or relative time expression to RFC 3339 UTC.
     *
     * @throws InvalidArgumentException When the expression cannot be parsed.
     */
    public static function toRfc3339(string|int|DateTimeInterface $time, ?DateTimeImmutable $now = null): string
    {
        if ($time instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($time)->setTimezone(new DateTimeZone('UTC'))->format(DATE_RFC3339);
        }
        if (is_int($time)) {
            return (new DateTimeImmutable('@' . $time))->format(DATE_RFC3339);
        }

        $time = trim($time);
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if (preg_match('/^(\d+)\s*([smhdw])$/i', $time, $m) === 1) {
            $unit = ['s' => 'seconds', 'm' => 'minutes', 'h' => 'hours', 'd' => 'days', 'w' => 'weeks'][strtolower($m[2])];

            return $now->modify(sprintf('-%d %s', (int) $m[1], $unit))->format(DATE_RFC3339);
        }
        if (is_numeric($time)) {
            return (new DateTimeImmutable('@' . (int) $time))->format(DATE_RFC3339);
        }

        try {
            $parsed = new DateTimeImmutable($time, new DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new InvalidArgumentException(sprintf(
                'Cannot parse time "%s". Use RFC 3339 (2026-09-15T10:00:00Z), "YYYY-MM-DD HH:MM", a Unix timestamp, or a relative duration like 15m, 2h, 3d.',
                $time
            ));
        }

        return $parsed->setTimezone(new DateTimeZone('UTC'))->format(DATE_RFC3339);
    }
}
