<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;
use Tourze\HttpRequestTaskBundle\Service\TaskRetryCalculator;

/**
 * @internal
 */
#[CoversClass(TaskRetryCalculator::class)]
final class TaskRetryCalculatorTest extends TestCase
{
    private TaskRetryCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new TaskRetryCalculator();
    }

    public function testCanRetryReturnsTrueWhenAttemptsLessThanMax(): void
    {
        $task = new HttpRequestTask();
        $task->setMaxAttempts(3);
        $task->setAttempts(1);

        $this->assertTrue($this->calculator->canRetry($task));
    }

    public function testCanRetryReturnsFalseWhenAttemptsEqualMax(): void
    {
        $task = new HttpRequestTask();
        $task->setMaxAttempts(3);
        $task->setAttempts(3);

        $this->assertFalse($this->calculator->canRetry($task));
    }

    public function testCanRetryReturnsFalseWhenAttemptsExceedMax(): void
    {
        $task = new HttpRequestTask();
        $task->setMaxAttempts(3);
        $task->setAttempts(5);

        $this->assertFalse($this->calculator->canRetry($task));
    }

    public function testCanRetryReturnsTrueWhenNoAttempts(): void
    {
        $task = new HttpRequestTask();
        $task->setMaxAttempts(3);
        $task->setAttempts(0);

        $this->assertTrue($this->calculator->canRetry($task));
    }

    public function testCalculateNextRetryDelayForFirstAttempt(): void
    {
        $task = new HttpRequestTask();
        $task->setRetryDelay(1000);
        $task->setRetryMultiplier(2.0);
        $task->setAttempts(0);

        $delay = $this->calculator->calculateNextRetryDelay($task);

        $this->assertSame(1000, $delay);
    }

    public function testCalculateNextRetryDelayWithExponentialBackoff(): void
    {
        $task = new HttpRequestTask();
        $task->setRetryDelay(1000);
        $task->setRetryMultiplier(2.0);
        $task->setAttempts(2);

        $delay = $this->calculator->calculateNextRetryDelay($task);

        // Base delay is 1000 * 2^(2-1) = 2000
        // Jitter adds 0-10% (0-200), so result should be between 2000 and 2200
        $this->assertGreaterThanOrEqual(2000, $delay);
        $this->assertLessThanOrEqual(2200, $delay);
    }

    public function testCalculateNextRetryDelayWithDifferentMultiplier(): void
    {
        $task = new HttpRequestTask();
        $task->setRetryDelay(500);
        $task->setRetryMultiplier(3.0);
        $task->setAttempts(3);

        $delay = $this->calculator->calculateNextRetryDelay($task);

        // Base delay is 500 * 3^(3-1) = 4500
        // Jitter adds 0-10% (0-450), so result should be between 4500 and 4950
        $this->assertGreaterThanOrEqual(4500, $delay);
        $this->assertLessThanOrEqual(4950, $delay);
    }

    public function testCalculateNextRetryDelayJitterRandomness(): void
    {
        $task = new HttpRequestTask();
        $task->setRetryDelay(1000);
        $task->setRetryMultiplier(2.0);
        $task->setAttempts(1);

        $delays = [];
        for ($i = 0; $i < 10; ++$i) {
            $delays[] = $this->calculator->calculateNextRetryDelay($task);
        }

        // All delays should be in valid range (1000 to 1100)
        foreach ($delays as $delay) {
            $this->assertGreaterThanOrEqual(1000, $delay);
            $this->assertLessThanOrEqual(1100, $delay);
        }
    }

    public function testIsScheduledForFutureReturnsFalseWhenNoScheduledTime(): void
    {
        $task = new HttpRequestTask();

        $this->assertFalse($this->calculator->isScheduledForFuture($task));
    }

    public function testIsScheduledForFutureReturnsTrueWhenScheduledInFuture(): void
    {
        $task = new HttpRequestTask();
        $futureTime = (new \DateTimeImmutable())->modify('+1 hour');
        $task->setScheduledTime($futureTime);

        $this->assertTrue($this->calculator->isScheduledForFuture($task));
    }

    public function testIsScheduledForFutureReturnsFalseWhenScheduledInPast(): void
    {
        $task = new HttpRequestTask();
        $pastTime = (new \DateTimeImmutable())->modify('-1 hour');
        $task->setScheduledTime($pastTime);

        $this->assertFalse($this->calculator->isScheduledForFuture($task));
    }

    public function testIsScheduledForFutureReturnsFalseWhenScheduledNow(): void
    {
        $task = new HttpRequestTask();
        // Use a time in the past to ensure it's not in the future
        $now = (new \DateTimeImmutable())->modify('-1 second');
        $task->setScheduledTime($now);

        $this->assertFalse($this->calculator->isScheduledForFuture($task));
    }

    public function testCalculateNextRetryDelayWithLargeAttempts(): void
    {
        $task = new HttpRequestTask();
        $task->setRetryDelay(100);
        $task->setRetryMultiplier(2.0);
        $task->setAttempts(5);

        $delay = $this->calculator->calculateNextRetryDelay($task);

        // Base delay is 100 * 2^(5-1) = 1600
        // Jitter adds 0-10% (0-160), so result should be between 1600 and 1760
        $this->assertGreaterThanOrEqual(1600, $delay);
        $this->assertLessThanOrEqual(1760, $delay);
    }
}
