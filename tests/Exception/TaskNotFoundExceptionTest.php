<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\HttpRequestTaskBundle\Exception\HttpRequestTaskException;
use Tourze\HttpRequestTaskBundle\Exception\TaskNotFoundException;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;

/**
 * @internal
 */
#[CoversClass(TaskNotFoundException::class)]
final class TaskNotFoundExceptionTest extends AbstractExceptionTestCase
{
    public function testExceptionInheritsFromHttpRequestTaskException(): void
    {
        $exception = new TaskNotFoundException();

        $this->assertInstanceOf(HttpRequestTaskException::class, $exception);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testExceptionCanBeCreatedWithMessage(): void
    {
        $message = 'Task with ID 123 not found';
        $exception = new TaskNotFoundException($message);

        $this->assertEquals($message, $exception->getMessage());
        $this->assertEquals(0, $exception->getCode());
        $this->assertNull($exception->getPrevious());
    }

    public function testExceptionCanBeCreatedWithAllParameters(): void
    {
        $message = 'Task with UUID abc-123 not found';
        $code = 404;
        $previous = new \Exception('Database error');

        $exception = new TaskNotFoundException($message, $code, $previous);

        $this->assertEquals($message, $exception->getMessage());
        $this->assertEquals($code, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testExceptionForSpecificTaskId(): void
    {
        $taskId = 42;
        $message = "Task with ID {$taskId} not found";
        $exception = new TaskNotFoundException($message, 404);

        $this->assertEquals($message, $exception->getMessage());
        $this->assertEquals(404, $exception->getCode());
        $this->assertStringContainsString('42', $exception->getMessage());
    }

    public function testExceptionWithoutParameters(): void
    {
        $exception = new TaskNotFoundException();

        $this->assertEquals('', $exception->getMessage());
        $this->assertEquals(0, $exception->getCode());
        $this->assertNull($exception->getPrevious());
    }
}
