<?php

declare(strict_types=1);

namespace Ldkafka\GoogleCloudLogging\Tests\Unit;

use Ldkafka\GoogleCloudLogging\Auth\ServiceAccountCredentials;
use Ldkafka\GoogleCloudLogging\Exception\ApiException;
use Ldkafka\GoogleCloudLogging\Log\CloudLoggingLogger;
use Ldkafka\GoogleCloudLogging\Logging\CloudLoggingClient;
use Ldkafka\GoogleCloudLogging\Tests\Support\FakeHttpClient;
use Ldkafka\GoogleCloudLogging\Tests\Support\GoogleKeyFixture;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CloudLoggingLoggerTest extends TestCase
{
    private GoogleKeyFixture $key;
    private FakeHttpClient $http;
    private CloudLoggingClient $client;

    protected function setUp(): void
    {
        $this->key = new GoogleKeyFixture();
        $this->http = (new FakeHttpClient())->queueToken();
        $this->client = new CloudLoggingClient(new ServiceAccountCredentials($this->key->path, $this->http), $this->http, 'p');
    }

    public function testRecordsAreBatchedAndFlushedWithSeverityAndContext(): void
    {
        $this->http->queue(200, []);
        $logger = new CloudLoggingLogger($this->client, 'my-app', ['type' => 'global'], ['env' => 'test'], 100);

        $logger->info('User {id} logged in', ['id' => 42, 'ip' => '10.0.0.1']);
        $logger->error('Failed: {e}', ['e' => new RuntimeException('boom')]);
        self::assertSame(2, $logger->getBufferedCount());
        self::assertCount(0, $this->http->requests, 'nothing sent (not even the token exchange) before flush');

        $logger->flush();

        self::assertSame(0, $logger->getBufferedCount());
        $body = $this->http->jsonBody(1);
        self::assertSame('projects/p/logs/my-app', $body['logName']);
        self::assertSame(['env' => 'test'], $body['labels']);
        self::assertSame('INFO', $body['entries'][0]['severity']);
        self::assertSame('User 42 logged in', $body['entries'][0]['jsonPayload']['message']);
        self::assertSame('10.0.0.1', $body['entries'][0]['jsonPayload']['ip']);
        self::assertSame('ERROR', $body['entries'][1]['severity']);
        self::assertSame('Failed: boom', $body['entries'][1]['jsonPayload']['message']);
        self::assertSame('RuntimeException', $body['entries'][1]['jsonPayload']['exception']['class']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $body['entries'][1]['timestamp']);
    }

    public function testAutomaticFlushAtBatchSize(): void
    {
        $this->http->queue(200, []);
        $logger = new CloudLoggingLogger($this->client, 'my-app', batchSize: 2);

        $logger->debug('one');
        self::assertCount(0, $this->http->requests);
        $logger->debug('two');
        self::assertCount(2, $this->http->requests, 'second record triggered the token exchange and the send');
        self::assertCount(2, $this->http->jsonBody(1)['entries']);
    }

    public function testSendFailuresAreSwallowedUnlessAsked(): void
    {
        $this->http->queue(403, ['error' => ['message' => 'denied', 'status' => 'PERMISSION_DENIED']]);
        $quiet = new CloudLoggingLogger($this->client, 'my-app');
        $quiet->warning('x');
        $quiet->flush();
        self::assertSame(0, $quiet->getBufferedCount());

        $this->http->queue(403, ['error' => ['message' => 'denied', 'status' => 'PERMISSION_DENIED']]);
        $loud = new CloudLoggingLogger($this->client, 'my-app', throwOnFailure: true);
        $loud->warning('x');
        $this->expectException(ApiException::class);
        $loud->flush();
    }
}
