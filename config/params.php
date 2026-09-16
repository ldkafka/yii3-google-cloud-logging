<?php

declare(strict_types=1);

use Ldkafka\GoogleCloudLogging\Auth\ServiceAccountCredentials;

/**
 * Defaults for Yii3 applications using yiisoft/config. Override in your application's params
 * (keep the key file path and any inline key material out of version control).
 */
return [
    'ldkafka/yii3-google-cloud-logging' => [
        // Path to the service-account JSON key. Empty = GOOGLE_APPLICATION_CREDENTIALS.
        'keyFile' => '',
        // Project whose logs are read/written. Empty = the key file's project_id.
        'projectId' => '',
        // OAuth scope(s). Reading only needs logging.read; add logging.write for CloudLoggingLogger.
        'scopes' => [ServiceAccountCredentials::SCOPE_LOGGING_READ],
        // HTTP timeouts (seconds).
        'timeout' => 30,
        // Use the application's PSR-16 cache (Psr\SimpleCache\CacheInterface) for access tokens.
        'useCache' => true,
        // MCP tool (only when ldkafka/yii3-mcp-server is installed and the tool is registered).
        'tool' => [
            'name' => 'cloud_logs',
            'description' => '',
            'maxMessageChars' => 400,
        ],
    ],
];
