<?php

declare(strict_types=1);

namespace Ldkafka\GoogleCloudLogging\Exception;

use RuntimeException;

/**
 * Any failure talking to Google: configuration, authentication, transport, or an API error
 * response. Messages are safe to surface to callers; they never contain key material.
 */
class GoogleCloudException extends RuntimeException
{
}
