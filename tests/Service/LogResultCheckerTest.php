<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestLog;
use Tourze\HttpRequestTaskBundle\Service\LogResultChecker;

/**
 * @internal
 */
#[CoversClass(LogResultChecker::class)]
final class LogResultCheckerTest extends TestCase
{
    private LogResultChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new LogResultChecker();
    }

    public function testIsSuccessReturnsTrue(): void
    {
        $log = new HttpRequestLog();
        $log->setResult(HttpRequestLog::RESULT_SUCCESS);

        $this->assertTrue($this->checker->isSuccess($log));
        $this->assertFalse($this->checker->isFailure($log));
        $this->assertFalse($this->checker->isNetworkError($log));
        $this->assertFalse($this->checker->isTimeout($log));
    }

    public function testIsFailureReturnsTrue(): void
    {
        $log = new HttpRequestLog();
        $log->setResult(HttpRequestLog::RESULT_FAILURE);

        $this->assertFalse($this->checker->isSuccess($log));
        $this->assertTrue($this->checker->isFailure($log));
        $this->assertFalse($this->checker->isNetworkError($log));
        $this->assertFalse($this->checker->isTimeout($log));
    }

    public function testIsNetworkErrorReturnsTrue(): void
    {
        $log = new HttpRequestLog();
        $log->setResult(HttpRequestLog::RESULT_NETWORK_ERROR);

        $this->assertFalse($this->checker->isSuccess($log));
        $this->assertFalse($this->checker->isFailure($log));
        $this->assertTrue($this->checker->isNetworkError($log));
        $this->assertFalse($this->checker->isTimeout($log));
    }

    public function testIsTimeoutReturnsTrue(): void
    {
        $log = new HttpRequestLog();
        $log->setResult(HttpRequestLog::RESULT_TIMEOUT);

        $this->assertFalse($this->checker->isSuccess($log));
        $this->assertFalse($this->checker->isFailure($log));
        $this->assertFalse($this->checker->isNetworkError($log));
        $this->assertTrue($this->checker->isTimeout($log));
    }
}
