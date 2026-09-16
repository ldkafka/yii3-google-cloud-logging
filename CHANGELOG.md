# Changelog

All notable changes to this package are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-09-16

### Added
- `ServiceAccountCredentials`: RS256 JWT bearer grant against the Google OAuth2 token endpoint,
  key from a file path, a decoded array, or `GOOGLE_APPLICATION_CREDENTIALS`; configurable scopes;
  optional PSR-16 token cache keyed by key identity and scope.
- `CloudLoggingClient`: `listLogs()`, `listEntries()`, `iterateEntries()` (page-following generator)
  and `writeEntries()` over the Logging REST API v2.
- `LogFilterBuilder`: fluent builder for the Logging query language (log name, resource type,
  container name, labels, arbitrary fields, minimum severity, time window with relative
  expressions, message substring, raw clauses).
- `LogEntry`: value object normalising message, source, service name and severity across
  text, JSON and proto payloads.
- `CloudLoggingLogger`: buffered PSR-3 logger writing to Cloud Logging.
- `CloudLoggingTool`: optional MCP tool (`status`, `list_logs`, `services`, `read`) for
  `ldkafka/yii3-mcp-server`, with configurable name and description.
- Yii3 `yiisoft/config` wiring (`config/params.php`, `config/di.php`).
- PHPUnit suite using a fake HTTP client and an embedded test key; GitHub Actions workflow.
