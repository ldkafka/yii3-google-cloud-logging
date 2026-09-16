<?php

declare(strict_types=1);

namespace Ldkafka\GoogleCloudLogging\Log;

use Ldkafka\GoogleCloudLogging\Exception\GoogleCloudException;
use Ldkafka\GoogleCloudLogging\Logging\CloudLoggingClient;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;

use function count;
use function date;
use function get_class;
use function is_scalar;
use function str_contains;
use function strtr;

/**
 * PSR-3 logger that ships records to Cloud Logging (`entries:write`).
 *
 * Records are buffered and sent in one request when the buffer reaches $batchSize, on
 * {@see flush()}, or on destruction. The service account needs the `logging.write` scope.
 * Send failures are swallowed by default (a logger must not take the application down); pass
 * $throwOnFailure to surface them.
 */
final class CloudLoggingLogger implements LoggerInterface
{
    use LoggerTrait;

    private const SEVERITY = [
        LogLevel::DEBUG => 'DEBUG',
        LogLevel::INFO => 'INFO',
        LogLevel::NOTICE => 'NOTICE',
        LogLevel::WARNING => 'WARNING',
        LogLevel::ERROR => 'ERROR',
        LogLevel::CRITICAL => 'CRITICAL',
        LogLevel::ALERT => 'ALERT',
        LogLevel::EMERGENCY => 'EMERGENCY',
    ];

    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    /**
     * @param CloudLoggingClient $client Client with write permission.
     * @param string $logName Short or full log name the records go to.
     * @param array<string, mixed> $resource Monitored resource for the entries.
     * @param array<string, string> $labels Labels applied to every entry.
     * @param int $batchSize Flush automatically after this many records.
     * @param bool $throwOnFailure Rethrow send failures instead of dropping the batch.
     */
    public function __construct(
        private CloudLoggingClient $client,
        private string $logName,
        private array $resource = ['type' => 'global'],
        private array $labels = [],
        private int $batchSize = 50,
        private bool $throwOnFailure = false,
    ) {
    }

    public function __destruct()
    {
        try {
            $this->flush();
        } catch (Throwable) {
            // Never throw from a destructor.
        }
    }

    /**
     * Buffer one record. `$message` is untyped so the class satisfies psr/log 1.x, 2.x and 3.x.
     *
     * @param mixed $level PSR-3 level.
     * @param string|Stringable $message Message with optional `{placeholders}`.
     * @param array<string, mixed> $context Placeholder values and structured data.
     */
    public function log($level, $message, array $context = []): void
    {
        $level = (string) $level;
        $text = self::interpolate((string) $message, $context);
        $payload = ['message' => $text];
        foreach ($context as $key => $value) {
            if ($value instanceof Throwable) {
                $payload['exception'] = ['class' => get_class($value), 'message' => $value->getMessage(), 'file' => $value->getFile(), 'line' => $value->getLine()];
            } elseif (is_scalar($value) || $value === null || $value instanceof Stringable) {
                $payload[(string) $key] = $value instanceof Stringable ? (string) $value : $value;
            } else {
                $payload[(string) $key] = $value;
            }
        }

        $this->buffer[] = [
            'severity' => self::SEVERITY[$level] ?? 'DEFAULT',
            'timestamp' => date(DATE_RFC3339_EXTENDED),
            'jsonPayload' => $payload,
        ];

        if (count($this->buffer) >= $this->batchSize) {
            $this->flush();
        }
    }

    /**
     * Send buffered records now.
     *
     * @throws GoogleCloudException Only when $throwOnFailure is set.
     */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }
        $batch = $this->buffer;
        $this->buffer = [];
        try {
            $this->client->writeEntries($batch, $this->logName, $this->resource, $this->labels);
        } catch (GoogleCloudException $e) {
            if ($this->throwOnFailure) {
                throw $e;
            }
        }
    }

    /**
     * Number of records waiting to be sent.
     */
    public function getBufferedCount(): int
    {
        return count($this->buffer);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $value) {
            $placeholder = '{' . $key . '}';
            if (!str_contains($message, $placeholder)) {
                continue;
            }
            if ($value instanceof Throwable) {
                // Throwable is Stringable too; keep the message, not the whole trace.
                $replace[$placeholder] = $value->getMessage();
            } elseif ($value === null || is_scalar($value) || $value instanceof Stringable) {
                $replace[$placeholder] = (string) $value;
            }
        }

        return $replace === [] ? $message : strtr($message, $replace);
    }
}
