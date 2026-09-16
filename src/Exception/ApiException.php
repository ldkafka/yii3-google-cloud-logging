<?php

declare(strict_types=1);

namespace Ldkafka\GoogleCloudLogging\Exception;

use Throwable;

/**
 * The API answered with an error status. Carries the HTTP status and Google's error status
 * string (for example `PERMISSION_DENIED`, `NOT_FOUND`) when present.
 */
final class ApiException extends GoogleCloudException
{
    public function __construct(
        string $message,
        private int $httpStatus,
        private ?string $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * Google's canonical status string, or null when the response carried none.
     */
    public function getStatus(): ?string
    {
        return $this->status;
    }
}
