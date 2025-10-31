<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestLog;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;
use Tourze\PHPUnitDoctrineEntity\AbstractEntityTestCase;

/**
 * @internal
 */
#[CoversClass(HttpRequestLog::class)]
final class HttpRequestLogTest extends AbstractEntityTestCase
{
    protected function createEntity(): object
    {
        return new HttpRequestLog();
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function propertiesProvider(): iterable
    {
        $task = new HttpRequestTask();
        $task->setUrl('https://example.com/test');

        yield 'attemptNumber' => ['attemptNumber', 1];
        yield 'executedTime' => ['executedTime', new \DateTimeImmutable()];
        yield 'requestHeaders' => ['requestHeaders', ['Content-Type' => 'application/json']];
        yield 'requestBody' => ['requestBody', '{"test": "data"}'];
        yield 'responseCode' => ['responseCode', 200];
        yield 'responseHeaders' => ['responseHeaders', ['Content-Type' => 'application/json']];
        yield 'responseBody' => ['responseBody', '{"success": true}'];
        yield 'responseTime' => ['responseTime', 150];
        yield 'result' => ['result', 'success'];
        yield 'errorMessage' => ['errorMessage', 'Error message'];
    }

    public function testResultConstants(): void
    {
        $this->assertEquals('success', HttpRequestLog::RESULT_SUCCESS);
        $this->assertEquals('failure', HttpRequestLog::RESULT_FAILURE);
        $this->assertEquals('timeout', HttpRequestLog::RESULT_TIMEOUT);
        $this->assertEquals('network_error', HttpRequestLog::RESULT_NETWORK_ERROR);
    }

    public function testToStringMethod(): void
    {
        $task = new HttpRequestTask();
        $task->setUrl('https://example.com/webhook');

        $log = new HttpRequestLog();
        $log->setTask($task);
        $log->setAttemptNumber(2);
        $log->setResult(HttpRequestLog::RESULT_SUCCESS);

        $string = $log->__toString();

        $this->assertStringContainsString('Log #0', $string);
        $this->assertStringContainsString('Attempt: 2', $string);
        $this->assertStringContainsString('Result: success', $string);
        $this->assertStringContainsString($task->getUuid(), $string);
    }
}
