<?php

declare(strict_types=1);

namespace Ldkafka\GoogleCloudLogging\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Ldkafka\GoogleCloudLogging\Logging\LogFilterBuilder;
use PHPUnit\Framework\TestCase;

final class LogFilterBuilderTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-15T12:00:00Z', new DateTimeZone('UTC'));
    }

    public function testEmptyBuilderProducesEmptyFilter(): void
    {
        self::assertSame('', (new LogFilterBuilder('p'))->build());
        self::assertSame('', (string) (new LogFilterBuilder('p'))->log('')->containerName(' ')->contains(''));
    }

    public function testShortLogNameIsExpandedAndFullNameKept(): void
    {
        self::assertSame('logName = "projects/p/logs/gcplogs-docker-driver"', (new LogFilterBuilder('p'))->log('gcplogs-docker-driver')->build());
        self::assertSame('logName = "projects/other/logs/x"', (new LogFilterBuilder('p'))->log('projects/other/logs/x')->build());
    }

    public function testClausesAreAndedAndQuoted(): void
    {
        $filter = (new LogFilterBuilder('p'))
            ->containerName('aura-yii-fpm')
            ->excludeContainerName('nginx')
            ->resourceType('global')
            ->label('env', 'prod')
            ->field('httpRequest.status', '>=', 500)
            ->field('jsonPayload.ok', '=', false)
            ->minSeverity('warning')
            ->since('2h', $this->now)
            ->until('2026-09-15 11:30', $this->now)
            ->contains('said "hi" \\ bye')
            ->raw('resource.type = "global"')
            ->build();

        self::assertSame(
            'jsonPayload.container.name : "aura-yii-fpm"'
            . ' AND NOT jsonPayload.container.name : "nginx"'
            . ' AND resource.type = "global"'
            . ' AND labels."env" = "prod"'
            . ' AND httpRequest.status >= 500'
            . ' AND jsonPayload.ok = false'
            . ' AND severity >= WARNING'
            . ' AND timestamp >= "2026-09-15T10:00:00+00:00"'
            . ' AND timestamp <= "2026-09-15T11:30:00+00:00"'
            . ' AND (textPayload : "said \"hi\" \\\\ bye" OR jsonPayload.message : "said \"hi\" \\\\ bye" OR jsonPayload.data : "said \"hi\" \\\\ bye")'
            . ' AND (resource.type = "global")',
            $filter
        );
    }

    public function testTimeExpressions(): void
    {
        self::assertSame('2026-09-15T11:45:00+00:00', LogFilterBuilder::toRfc3339('15m', $this->now));
        self::assertSame('2026-09-12T12:00:00+00:00', LogFilterBuilder::toRfc3339('3d', $this->now));
        self::assertSame('2026-09-08T12:00:00+00:00', LogFilterBuilder::toRfc3339('1w', $this->now));
        self::assertSame('2026-09-15T09:00:00+00:00', LogFilterBuilder::toRfc3339('2026-09-15T11:00:00+02:00', $this->now));
        self::assertSame('2026-09-15T12:00:00+00:00', LogFilterBuilder::toRfc3339((string) $this->now->getTimestamp(), $this->now));
        self::assertSame('2026-09-15T12:00:00+00:00', LogFilterBuilder::toRfc3339($this->now->getTimestamp(), $this->now));
        self::assertSame('2026-09-15T10:00:00+00:00', LogFilterBuilder::toRfc3339(new DateTimeImmutable('2026-09-15T12:00:00+02:00')));
    }

    public function testUnparseableTimeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LogFilterBuilder::toRfc3339('yesterday-ish', $this->now);
    }

    public function testUnknownSeverityIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new LogFilterBuilder('p'))->minSeverity('LOUD');
    }

    public function testUnknownOperatorIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new LogFilterBuilder('p'))->field('x', 'LIKE', 'y');
    }
}
