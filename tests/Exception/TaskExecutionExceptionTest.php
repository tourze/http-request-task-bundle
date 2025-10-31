<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\HttpRequestTaskBundle\Exception\HttpRequestTaskException;
use Tourze\HttpRequestTaskBundle\Exception\TaskExecutionException;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;

/**
 * @internal
 */
#[CoversClass(TaskExecutionException::class)]
final class TaskExecutionExceptionTest extends AbstractExceptionTestCase
{
    public function testExceptionInheritsFromHttpRequestTaskException(): void
    {
        $exception = new TaskExecutionException();

        $this->assertInstanceOf(HttpRequestTaskException::class, $exception);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testExceptionCanBeCreatedWithMessage(): void
    {
        $message = 'Task execution failed: connection timeout';
        $exception = new TaskExecutionException($message);

        $this->assertEquals($message, $exception->getMessage());
        $this->assertEquals(0, $exception->getCode());
        $this->assertNull($exception->getPrevious());
    }

    public function testExceptionCanBeCreatedWithAllParameters(): void
    {
        $message = 'HTTP request failed with status 500';
        $code = 500;
        $previous = new \Exception('Internal server error');

        $exception = new TaskExecutionException($message, $code, $previous);

        $this->assertEquals($message, $exception->getMessage());
        $this->assertEquals($code, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testExceptionForNetworkError(): void
    {
        $networkError = new \Exception('Network unreachable');
        $exception = new TaskExecutionException('Network error occurred', 0, $networkError);

        $this->assertEquals('Network error occurred', $exception->getMessage());
        $this->assertSame($networkError, $exception->getPrevious());
    }

    public function testExceptionWithDefaultConstructor(): void
    {
        $exception = new TaskExecutionException();

        $this->assertEquals('', $exception->getMessage());
        $this->assertEquals(0, $exception->getCode());
        $this->assertNull($exception->getPrevious());
    }
}
