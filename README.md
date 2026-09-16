# yii3-google-cloud-logging

Google Cloud Logging for PHP 8 and Yii3 **without the Google SDK**. A few small classes on top of
`ext-curl` and `ext-openssl` give you:

- **Service-account authentication** (JWT bearer grant, RS256) with optional PSR-16 token caching.
- **Read** log entries (`entries:list`) with a fluent **filter builder** for the Logging query language.
- **Stream** large result sets with a generator that follows page tokens.
- **List** log names in a project (`logs.list`).
- **Write** entries (`entries:write`) and a buffered **PSR-3 logger** that ships your application log
  to Cloud Logging.
- An optional **MCP tool** (via [ldkafka/yii3-mcp-server](https://github.com/ldkafka/yii3-mcp-server))
  so AI assistants can inspect your logs with `status`, `list_logs`, `services` and `read` actions.

The package holds **no credentials** and makes no assumptions about your project: you pass a
service-account key (file path or decoded array) and, optionally, a project id.

Designed around the Docker `gcplogs` log driver (entries with `jsonPayload.container.name` and
`jsonPayload.message`) but works with any log source.

## Requirements

- PHP 8.1+ with `curl`, `json` and `openssl`.
- A Google service-account JSON key with at least the **Logs Viewer** role
  (`roles/logging.viewer`) for reading, or **Logs Writer** (`roles/logging.logWriter`) for writing.

## Installation

```bash
composer require ldkafka/yii3-google-cloud-logging
```

## Quick start (plain PHP)

```php
use Ldkafka\GoogleCloudLogging\Auth\ServiceAccountCredentials;
use Ldkafka\GoogleCloudLogging\Http\CurlHttpClient;
use Ldkafka\GoogleCloudLogging\Logging\CloudLoggingClient;
use Ldkafka\GoogleCloudLogging\Logging\LogFilterBuilder;

$http        = new CurlHttpClient(timeout: 30);
$credentials = new ServiceAccountCredentials('/etc/secrets/logs-reader.json', $http);
$client      = new CloudLoggingClient($credentials, $http); // project id taken from the key

$filter = (new LogFilterBuilder($client->getProjectId()))
    ->log('gcplogs-docker-driver')
    ->containerName('my-service')
    ->since('2h')
    ->contains('Fatal error');

foreach ($client->iterateEntries($filter, maxEntries: 200) as $entry) {
    printf("%s %-8s [%s] %s\n", $entry->getTimestamp(), $entry->getSeverity(), $entry->getSource(), $entry->getMessage());
}
```

`ServiceAccountCredentials::fromEnvironment($http)` reads the key path from
`GOOGLE_APPLICATION_CREDENTIALS`, and the constructor also accepts the decoded key as an array if
you keep it in a secrets manager rather than on disk.

### Scopes and the token cache

Reading needs only `SCOPE_LOGGING_READ` (the default). Pass `SCOPE_LOGGING_WRITE` (or both) for
writing. Tokens are valid for an hour; give the credentials a PSR-16 cache so that every PHP-FPM
request does not repeat the exchange:

```php
$credentials = new ServiceAccountCredentials(
    $keyFile,
    $http,
    [ServiceAccountCredentials::SCOPE_LOGGING_READ, ServiceAccountCredentials::SCOPE_LOGGING_WRITE],
    $psr16Cache,
);
```

The cache key is bound to the key file's identity and scopes, so rotating the key never serves a
stale token.

## Reading entries

```php
// One page, newest first, as LogEntry objects.
$page = $client->listEntries($filter, pageSize: 100);
foreach ($page['entries'] as $entry) { /* ... */ }
$next = $page['nextPageToken']; // null when exhausted

// Follow pages automatically, stop after N entries.
foreach ($client->iterateEntries($filter, maxEntries: 5000, newestFirst: false) as $entry) { /* ... */ }

// Which logs exist in the project?
$client->listLogs()['logNames'];
```

`LogEntry` wraps the raw API object (`toArray()`) and normalises the common shapes:

| Method | Returns |
|---|---|
| `getMessage()` | `textPayload`, else `jsonPayload.message`, else the JSON payload, else the proto payload summary |
| `getSource()` | container name (gcplogs), else `resource.type:instance`, else a `service`/`app` label |
| `getServiceName()` | Swarm service name (`web.1.abc123` becomes `web`) |
| `getSeverity()`, `getTimestamp()`, `getLogName()`, `getShortLogName()`, `getInsertId()` | the usual fields |

### Filter builder

Every method appends an `AND` clause; strings are escaped for the
[Logging query language](https://cloud.google.com/logging/docs/view/logging-query-language).

| Method | Clause |
|---|---|
| `log('name')` | `logName = "projects/<id>/logs/name"` (full names are kept as given) |
| `resourceType('gce_instance')` | `resource.type = "gce_instance"` |
| `containerName('web')` / `excludeContainerName('proxy')` | `jsonPayload.container.name : "web"` / `NOT ...` |
| `label('env', 'prod')` | `labels."env" = "prod"` |
| `field('httpRequest.status', '>=', 500)` | any field with `= != < <= > >= :` |
| `minSeverity('WARNING')` | `severity >= WARNING` |
| `since('2h')` / `until('2026-09-15 11:30')` | `timestamp >= / <= "<RFC 3339>"` |
| `contains('boom')` | matches `textPayload`, `jsonPayload.message` or `jsonPayload.data` |
| `raw('...')` | anything else, wrapped in parentheses |

Time values accept RFC 3339, `YYYY-MM-DD HH:MM` (UTC), Unix timestamps, `DateTimeInterface`, or a
relative window (`15m`, `2h`, `3d`, `1w`).

## Writing entries

```php
$client->writeEntries(
    [['severity' => 'ERROR', 'jsonPayload' => ['message' => 'Payment failed', 'orderId' => 42]]],
    logName: 'my-app',
    resource: ['type' => 'global'],
    labels: ['env' => 'prod'],
);
```

### PSR-3 logger

`CloudLoggingLogger` buffers records and sends them in batches (default 50, on `flush()` and on
destruct). Placeholders are interpolated, the PSR-3 level becomes the Cloud Logging severity, and
scalar context goes into `jsonPayload`; any `Throwable` in the context is serialised under `exception` as
class, message, file and line.

```php
use Ldkafka\GoogleCloudLogging\Log\CloudLoggingLogger;

$logger = new CloudLoggingLogger($client, 'my-app', ['type' => 'global'], ['env' => 'prod']);
$logger->error('Sync failed for {property}', ['property' => 17, 'exception' => $e]);
$logger->flush();
```

Send failures are swallowed by default (a logging outage must not take the application down);
pass `throwOnFailure: true` to surface them.

## Yii3 wiring

The package ships `config/params.php` and `config/di.php` for
[yiisoft/config](https://github.com/yiisoft/config). Override the params in your application:

```php
// config/common/params.php (or an untracked params.local.php)
'ldkafka/yii3-google-cloud-logging' => [
    'keyFile' => dirname(__DIR__, 2) . '/config/environments/prod/google-logs-reader.json',
    'projectId' => '',            // default: the key's project_id
    'scopes' => [ServiceAccountCredentials::SCOPE_LOGGING_READ],
    'timeout' => 30,
    'useCache' => true,           // uses the app's Psr\SimpleCache\CacheInterface if bound
    'tool' => ['name' => 'cloud_logs', 'description' => '', 'maxMessageChars' => 400],
],
```

The container then provides `CloudLoggingClient`, `ServiceAccountCredentials`, `HttpClientInterface`
and, if you register it with your MCP server, `CloudLoggingTool`. Keep the key file out of version
control and never put inline key material in a tracked params file.

## MCP tool

With `ldkafka/yii3-mcp-server` installed, register `CloudLoggingTool` as one of the server's tools.
It is read-only (`readOnlyHint`) and offers:

| Action | Purpose |
|---|---|
| `status` | Key present? Token exchange works? Logs API reachable? |
| `list_logs` | Log names in the project |
| `services` | Samples the newest entries in a window and groups them by service with severity counts |
| `read` | Entries filtered by `log`, `service`, `exclude`, `resource_type`, `severity`, `since`/`until`, `contains`, raw `filter`; paged via `page_token`; `format` text or json |

The tool name and the first sentence of its description are configurable so the same tool can be
called `google_logs` in one app and `cloud_logs` in another.

## Testing

```bash
composer install
vendor/bin/phpunit
```

The suite uses a fake HTTP client and an embedded throwaway RSA key, so it never contacts Google.

## License

BSD-3-Clause. See [LICENSE](LICENSE).
