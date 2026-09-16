<?php

declare(strict_types=1);

namespace Ldkafka\GoogleCloudLogging\Tests\Unit;

use Ldkafka\GoogleCloudLogging\Auth\ServiceAccountCredentials;
use Ldkafka\GoogleCloudLogging\Exception\ApiException;
use Ldkafka\GoogleCloudLogging\Exception\GoogleCloudException;
use Ldkafka\GoogleCloudLogging\Logging\CloudLoggingClient;
use Ldkafka\GoogleCloudLogging\Logging\LogEntry;
use Ldkafka\GoogleCloudLogging\Logging\LogFilterBuilder;
use Ldkafka\GoogleCloudLogging\Tests\Support\FakeHttpClient;
use Ldkafka\GoogleCloudLogging\Tests\Support\GoogleKeyFixture;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

final class CloudLoggingClientTest extends TestCase
{
    private GoogleKeyFixture $key;
    private FakeHttpClient $http;
    private CloudLoggingClient $client;

    protected function setUp(): void
    {
        $this->key = new GoogleKeyFixture();
        $this->http = (new FakeHttpClient())->queueToken();
        $this->client = new CloudLoggingClient(new ServiceAccountCredentials($this->key->path, $this->http), $this->http, 'my-proj');
    }

    public function testProjectIdDefaultsToTheKey(): void
    {
        $client = new CloudLoggingClient(new ServiceAccountCredentials($this->key->path, $this->http), $this->http);

        self::assertSame('example-project', $client->getProjectId());
    }

    public function testMissingProjectIdIsAnError(): void
    {
        $key = json_decode((string) file_get_contents($this->key->path), true);
        unset($key['project_id']);
        $client = new CloudLoggingClient(new ServiceAccountCredentials($key, $this->http), $this->http);

        $this->expectException(GoogleCloudException::class);
        $client->getProjectId();
    }

    public function testListLogsUsesTheProjectParentAndBearerToken(): void
    {
        $this->http->queue(200, ['logNames' => ['projects/my-proj/logs/a', 'projects/my-proj/logs/b'], 'nextPageToken' => 'p2']);

        $result = $this->client->listLogs(50);

        self::assertSame(['projects/my-proj/logs/a', 'projects/my-proj/logs/b'], $result['logNames']);
        self::assertSame('p2', $result['nextPageToken']);
        $request = $this->http->requests[1];
        self::assertSame('GET', $request['method']);
        self::assertSame(CloudLoggingClient::BASE_URL . '/projects/my-proj/logs?pageSize=50', $request['url']);
        self::assertSame('Bearer tok', $request['headers']['Authorization']);
    }

    public function testListEntriesPostsFilterOrderAndPagingAndWrapsEntries(): void
    {
        $this->http->queue(200, ['entries' => [['insertId' => '1', 'textPayload' => 'hello']]]);

        $result = $this->client->listEntries((new LogFilterBuilder('my-proj'))->minSeverity('ERROR'), 5000, false, 'tok-abc');

        self::assertInstanceOf(LogEntry::class, $result['entries'][0]);
        self::assertSame('hello', $result['entries'][0]->getMessage());
        self::assertNull($result['nextPageToken']);
        self::assertSame([
            'resourceNames' => ['projects/my-proj'],
            'orderBy' => 'timestamp asc',
            'pageSize' => CloudLoggingClient::MAX_PAGE_SIZE,
            'filter' => 'severity >= ERROR',
            'pageToken' => 'tok-abc',
        ], $this->http->jsonBody(1));
    }

    public function testIterateEntriesFollowsPagesUpToTheMaximum(): void
    {
        $this->http
            ->queue(200, ['entries' => [['insertId' => '1'], ['insertId' => '2']], 'nextPageToken' => 'p2'])
            ->queue(200, ['entries' => [['insertId' => '3'], ['insertId' => '4']], 'nextPageToken' => 'p3']);

        $ids = array_map(static fn (LogEntry $e): string => $e->getInsertId(), iterator_to_array($this->client->iterateEntries('', 3, true, 2), false));

        self::assertSame(['1', '2', '3'], $ids);
        self::assertCount(3, $this->http->requests, 'token + two pages; the third page is never requested');
        self::assertSame('p2', $this->http->jsonBody(2)['pageToken']);
    }

    public function testWriteEntriesPostsToEntriesWriteWithDefaults(): void
    {
        $this->http->queue(200, []);

        $this->client->writeEntries(
            [['jsonPayload' => ['message' => 'hi'], 'severity' => 'INFO'], ['foo' => 'bar']],
            'my-app',
            ['type' => 'global'],
            ['env' => 'test']
        );

        $request = $this->http->requests[1];
        self::assertSame(CloudLoggingClient::BASE_URL . '/entries:write', $request['url']);
        $body = $this->http->jsonBody(1);
        self::assertSame('projects/my-proj/logs/my-app', $body['logName']);
        self::assertSame(['type' => 'global'], $body['resource']);
        self::assertSame(['env' => 'test'], $body['labels']);
        self::assertTrue($body['partialSuccess']);
        self::assertSame('', $body['entries'][1]['textPayload'], 'entries without a payload get an empty textPayload');
    }

    public function testWriteEntriesWithNothingToWriteMakesNoRequest(): void
    {
        $this->client->writeEntries([], 'my-app');

        self::assertCount(0, $this->http->requests);
    }

    public function testApiErrorsSurfaceGoogleMessageAndStatus(): void
    {
        $this->http->queue(403, ['error' => ['code' => 403, 'message' => 'Permission logging.logEntries.list denied', 'status' => 'PERMISSION_DENIED']]);

        try {
            $this->client->listEntries('', 10);
            self::fail('expected exception');
        } catch (ApiException $e) {
            self::assertSame(403, $e->getHttpStatus());
            self::assertSame('PERMISSION_DENIED', $e->getStatus());
            self::assertStringContainsString('Permission logging.logEntries.list denied', $e->getMessage());
        }
    }
}
