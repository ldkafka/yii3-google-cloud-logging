<?php

declare(strict_types=1);

namespace Ldkafka\GoogleCloudLogging\Tests\Unit;

use Ldkafka\GoogleCloudLogging\Auth\ServiceAccountCredentials;
use Ldkafka\GoogleCloudLogging\Logging\CloudLoggingClient;
use Ldkafka\GoogleCloudLogging\Mcp\CloudLoggingTool;
use Ldkafka\GoogleCloudLogging\Tests\Support\FakeHttpClient;
use Ldkafka\GoogleCloudLogging\Tests\Support\GoogleKeyFixture;
use PHPUnit\Framework\TestCase;

use function json_decode;

final class CloudLoggingToolTest extends TestCase
{
    private GoogleKeyFixture $key;
    private FakeHttpClient $http;
    private CloudLoggingTool $tool;

    protected function setUp(): void
    {
        $this->key = new GoogleKeyFixture();
        $this->http = (new FakeHttpClient())->queueToken();
        $client = new CloudLoggingClient(new ServiceAccountCredentials($this->key->path, $this->http), $this->http, 'my-proj');
        $this->tool = new CloudLoggingTool($client, 'google_logs', 'Swarm container output.', 60);
    }

    /** @return list<array<string, mixed>> */
    private function sampleEntries(): array
    {
        return [
            ['logName' => 'projects/my-proj/logs/gcplogs-docker-driver', 'timestamp' => '2026-09-15T10:00:02Z', 'severity' => 'ERROR', 'jsonPayload' => ['container' => ['name' => '/aura-scheduler.1.xdi2lpq7cd6tl3xakcqd4hy8a'], 'message' => "PHP Fatal error: boom\nstack line"]],
            ['logName' => 'projects/my-proj/logs/gcplogs-docker-driver', 'timestamp' => '2026-09-15T10:00:01Z', 'severity' => 'INFO', 'jsonPayload' => ['container' => ['name' => '/aura-yii-fpm.1.y05vr67i4l7fuzfbia09smx8m'], 'message' => 'ready to handle connections']],
            ['logName' => 'projects/my-proj/logs/syslog', 'timestamp' => '2026-09-15T10:00:00Z', 'textPayload' => str_repeat('x', 200), 'resource' => ['type' => 'gce_instance', 'labels' => ['instance_id' => '42']]],
        ];
    }

    private function text(array $result): string
    {
        return $result['content'][0]['text'];
    }

    public function testNameAndDescriptionAreConfigurable(): void
    {
        self::assertSame('google_logs', $this->tool->getName());
        self::assertStringStartsWith('Swarm container output. Read Google Cloud Logging entries.', $this->tool->getDescription());
        self::assertTrue($this->tool->getAnnotations()['readOnlyHint']);
    }

    public function testUnknownActionIsAnError(): void
    {
        $result = $this->tool->execute(['action' => 'delete_everything']);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('Unknown action', $this->text($result));
    }

    public function testStatusWithoutKeyExplainsWhatToDo(): void
    {
        $client = new CloudLoggingClient(new ServiceAccountCredentials('/missing/key.json', $this->http), $this->http, 'my-proj');
        $result = (new CloudLoggingTool($client))->execute(['action' => 'status']);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('MISSING', $this->text($result));
    }

    public function testStatusReportsConnectivity(): void
    {
        $this->http->queue(200, ['logNames' => ['projects/my-proj/logs/a'], 'nextPageToken' => 'x']);

        $result = $this->tool->execute(['action' => 'status']);

        self::assertArrayNotHasKey('isError', $result);
        $text = $this->text($result);
        self::assertStringContainsString('Project: my-proj', $text);
        self::assertStringContainsString('Token exchange: OK', $text);
        self::assertStringContainsString('Logs API: OK (1 log name(s) in first page, more available)', $text);
    }

    public function testServicesGroupsBySwarmServiceWithSeverityCounts(): void
    {
        $this->http->queue(200, ['entries' => $this->sampleEntries()]);

        $text = $this->text($this->tool->execute(['action' => 'services', 'since' => '6h', 'exclude' => 'wordpress', 'resource_type' => 'global']));

        self::assertStringContainsString('Sampled 3 newest entries since 6h; sample reaches back to 2026-09-15T10:00:00Z (3 service(s))', $text);
        self::assertStringContainsString('aura-scheduler ', $text);
        self::assertStringContainsString('ERROR=1', $text);
        self::assertStringContainsString('gce_instance:42', $text);
        $body = $this->http->jsonBody(1);
        self::assertSame(CloudLoggingTool::SAMPLE_SIZE, $body['pageSize']);
        self::assertStringContainsString('resource.type = "global" AND NOT jsonPayload.container.name : "wordpress" AND timestamp >= ', $body['filter']);
    }

    public function testReadTextFormatIsOneLinePerEntryTruncatedAndPaged(): void
    {
        $this->http->queue(200, ['entries' => $this->sampleEntries(), 'nextPageToken' => 'more']);

        $text = $this->text($this->tool->execute(['action' => 'read', 'service' => 'aura', 'severity' => 'INFO', 'since' => '1h', 'contains' => 'boom', 'limit' => 5000]));

        self::assertStringContainsString('filter: jsonPayload.container.name : "aura" AND severity >= INFO AND timestamp >=', $text);
        self::assertStringContainsString('3 entrie(s), newest first', $text);
        self::assertStringContainsString('2026-09-15T10:00:02Z ERROR    [aura-scheduler.1.xdi2lpq7cd6tl3xakcqd4hy8a] PHP Fatal error: boom | stack line', $text);
        self::assertStringContainsString('[gce_instance:42] ' . str_repeat('x', 59) . '…', $text);
        self::assertStringContainsString('next page_token: more', $text);
        self::assertSame(CloudLoggingTool::MAX_LIMIT, $this->http->jsonBody(1)['pageSize']);
    }

    public function testReadJsonFormatReturnsRawEntries(): void
    {
        $this->http->queue(200, ['entries' => $this->sampleEntries()]);

        $decoded = json_decode($this->text($this->tool->execute(['action' => 'read', 'format' => 'json', 'limit' => 3, 'order' => 'asc'])), true);

        self::assertSame(3, $decoded['count']);
        self::assertSame('/aura-scheduler.1.xdi2lpq7cd6tl3xakcqd4hy8a', $decoded['entries'][0]['jsonPayload']['container']['name']);
        self::assertSame('timestamp asc', $this->http->jsonBody(1)['orderBy']);
    }

    public function testBadInputAndApiFailuresAreToolErrors(): void
    {
        $bad = $this->tool->execute(['action' => 'read', 'since' => 'whenever']);
        self::assertTrue($bad['isError']);
        self::assertStringContainsString('Cannot parse time "whenever"', $this->text($bad));

        $this->http->queue(403, ['error' => ['message' => 'denied']]);
        $denied = $this->tool->execute(['action' => 'list_logs']);
        self::assertTrue($denied['isError']);
        self::assertStringContainsString('HTTP 403', $this->text($denied));
    }
}
