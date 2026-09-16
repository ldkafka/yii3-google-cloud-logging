<?php

declare(strict_types=1);

use Ldkafka\GoogleCloudLogging\Auth\ServiceAccountCredentials;
use Ldkafka\GoogleCloudLogging\Http\CurlHttpClient;
use Ldkafka\GoogleCloudLogging\Http\HttpClientInterface;
use Ldkafka\GoogleCloudLogging\Logging\CloudLoggingClient;
use Ldkafka\GoogleCloudLogging\Mcp\CloudLoggingTool;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Yiisoft\Definitions\Reference;

/** @var array $params */

$config = $params['ldkafka/yii3-google-cloud-logging'];

$keyFile = (string) ($config['keyFile'] ?? '');
if ($keyFile === '') {
    $env = getenv('GOOGLE_APPLICATION_CREDENTIALS');
    $keyFile = is_string($env) ? $env : '';
}

return [
    HttpClientInterface::class => [
        'class' => CurlHttpClient::class,
        '__construct()' => ['timeout' => (int) ($config['timeout'] ?? 30)],
    ],

    ServiceAccountCredentials::class => [
        '__construct()' => [
            'keyFileOrArray' => $keyFile,
            'http' => Reference::to(HttpClientInterface::class),
            'scopes' => (array) ($config['scopes'] ?? [ServiceAccountCredentials::SCOPE_LOGGING_READ]),
            'cache' => !empty($config['useCache']) ? Reference::optional(CacheInterface::class) : null,
        ],
    ],

    CloudLoggingClient::class => [
        '__construct()' => [
            'credentials' => Reference::to(ServiceAccountCredentials::class),
            'http' => Reference::to(HttpClientInterface::class),
            'projectId' => ($config['projectId'] ?? '') !== '' ? (string) $config['projectId'] : null,
        ],
    ],

    CloudLoggingTool::class => [
        '__construct()' => [
            'client' => Reference::to(CloudLoggingClient::class),
            'name' => (string) ($config['tool']['name'] ?? 'cloud_logs'),
            'description' => (string) ($config['tool']['description'] ?? ''),
            'maxMessageChars' => (int) ($config['tool']['maxMessageChars'] ?? 400),
            'logger' => Reference::optional(LoggerInterface::class),
        ],
    ],
];
