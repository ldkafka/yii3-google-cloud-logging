<?php

declare(strict_types=1);

namespace Ldkafka\GoogleCloudLogging\Tests\Unit;

use Ldkafka\GoogleCloudLogging\Logging\LogEntry;
use PHPUnit\Framework\TestCase;

final class LogEntryTest extends TestCase
{
    public function testGcplogsEntry(): void
    {
        $entry = new LogEntry([
            'insertId' => 'abc',
            'logName' => 'projects/p/logs/gcplogs-docker-driver',
            'timestamp' => '2026-09-15T10:00:02Z',
            'jsonPayload' => ['container' => ['name' => '/aura-scheduler.1.xdi2lpq7cd6tl3xakcqd4hy8a', 'id' => 'x'], 'message' => "boom\nline 2"],
        ]);

        self::assertSame('abc', $entry->getInsertId());
        self::assertSame('DEFAULT', $entry->getSeverity());
        self::assertSame('gcplogs-docker-driver', $entry->getShortLogName());
        self::assertSame('aura-scheduler.1.xdi2lpq7cd6tl3xakcqd4hy8a', $entry->getSource());
        self::assertSame('aura-scheduler', $entry->getServiceName());
        self::assertSame("boom\nline 2", $entry->getMessage());
    }

    public function testTextPayloadWithResourceLabels(): void
    {
        $entry = new LogEntry([
            'severity' => 'ERROR',
            'textPayload' => 'plain',
            'resource' => ['type' => 'gce_instance', 'labels' => ['instance_id' => '42']],
        ]);

        self::assertSame('ERROR', $entry->getSeverity());
        self::assertSame('gce_instance:42', $entry->getSource());
        self::assertSame('gce_instance:42', $entry->getServiceName());
        self::assertSame('plain', $entry->getMessage());
    }

    public function testJsonPayloadWithoutMessageIsRenderedAsJson(): void
    {
        $entry = new LogEntry(['jsonPayload' => ['container' => ['name' => 'x'], 'code' => 7, 'detail' => 'y'], 'labels' => ['app' => 'api']]);

        self::assertSame('{"code":7,"detail":"y"}', $entry->getMessage());
        self::assertSame('x', $entry->getSource());
    }

    public function testProtoPayloadAndUnknownShapes(): void
    {
        self::assertSame('type.googleapis.com/google.cloud.audit.AuditLog SetIamPolicy', (new LogEntry(['protoPayload' => ['@type' => 'type.googleapis.com/google.cloud.audit.AuditLog', 'methodName' => 'SetIamPolicy']]))->getMessage());
        $empty = new LogEntry([]);
        self::assertSame('', $empty->getMessage());
        self::assertSame('unknown', $empty->getSource());
        self::assertSame('', $empty->getTimestamp());
        self::assertSame('service', (new LogEntry(['labels' => ['service' => 'service']]))->getSource());
    }
}
