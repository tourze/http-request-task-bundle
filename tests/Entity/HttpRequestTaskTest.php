<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;
use Tourze\PHPUnitDoctrineEntity\AbstractEntityTestCase;

/**
 * @internal
 */
#[CoversClass(HttpRequestTask::class)]
final class HttpRequestTaskTest extends AbstractEntityTestCase
{
    protected function createEntity(): object
    {
        return new HttpRequestTask();
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function propertiesProvider(): iterable
    {
        yield 'url' => ['url', 'https://example.com/test'];
        yield 'method' => ['method', 'POST'];
        yield 'headers' => ['headers', ['Content-Type' => 'application/json']];
        yield 'body' => ['body', '{"test": "data"}'];
        yield 'contentType' => ['contentType', 'application/json'];
        yield 'priority' => ['priority', 3];
        yield 'maxAttempts' => ['maxAttempts', 5];
        yield 'timeout' => ['timeout', 60];
        yield 'retryDelay' => ['retryDelay', 2000];
        yield 'retryMultiplier' => ['retryMultiplier', 1.5];
        yield 'lastResponseCode' => ['lastResponseCode', 200];
        yield 'lastResponseBody' => ['lastResponseBody', 'response body'];
        yield 'lastErrorMessage' => ['lastErrorMessage', 'error message'];
        yield 'scheduledTime' => ['scheduledTime', new \DateTimeImmutable('+1 hour')];
        yield 'startedTime' => ['startedTime', new \DateTimeImmutable()];
        yield 'completedTime' => ['completedTime', new \DateTimeImmutable()];
        yield 'metadata' => ['metadata', ['key' => 'value']];
        yield 'rateLimitKey' => ['rateLimitKey', 'test_key'];
        yield 'rateLimitPerSecond' => ['rateLimitPerSecond', 10];
    }

    public function testCanRetry(): void
    {
        $task = new HttpRequestTask();
        $task->setMaxAttempts(3);

        $this->assertTrue($task->canRetry());

        $task->incrementAttempts();
        $this->assertTrue($task->canRetry());

        $task->incrementAttempts();
        $this->assertTrue($task->canRetry());

        $task->incrementAttempts();
        $this->assertFalse($task->canRetry());
    }

    public function testCalculateNextRetryDelay(): void
    {
        $task = new HttpRequestTask();
        $task->setRetryDelay(1000);
        $task->setRetryMultiplier(2.0);

        $this->assertEquals(1000, $task->calculateNextRetryDelay());

        $task->incrementAttempts();
        $delay1 = $task->calculateNextRetryDelay();
        $this->assertGreaterThanOrEqual(1000, $delay1);
        $this->assertLessThanOrEqual(1100, $delay1);

        $task->incrementAttempts();
        $delay2 = $task->calculateNextRetryDelay();
        $this->assertGreaterThanOrEqual(2000, $delay2);
        $this->assertLessThanOrEqual(2200, $delay2);
    }

    public function testIsScheduledForFuture(): void
    {
        $task = new HttpRequestTask();
        $this->assertFalse($task->isScheduledForFuture());

        $task->setScheduledTime(new \DateTimeImmutable('+1 hour'));
        $this->assertTrue($task->isScheduledForFuture());

        $task->setScheduledTime(new \DateTimeImmutable('-1 hour'));
        $this->assertFalse($task->isScheduledForFuture());
    }
}
