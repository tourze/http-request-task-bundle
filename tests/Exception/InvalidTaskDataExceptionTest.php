<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\HttpRequestTaskBundle\Exception\HttpRequestTaskException;
use Tourze\HttpRequestTaskBundle\Exception\InvalidTaskDataException;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;

/**
 * @internal
 */
#[CoversClass(InvalidTaskDataException::class)]
class InvalidTaskDataExceptionTest extends AbstractExceptionTestCase
{
    public function testExceptionInheritance(): void
    {
        $exception = new InvalidTaskDataException();
        $this->assertInstanceOf(HttpRequestTaskException::class, $exception);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testDefaultMessage(): void
    {
        $exception = new InvalidTaskDataException();
        $this->assertSame('Invalid task data', $exception->getMessage());
    }

    public function testCustomMessage(): void
    {
        $message = 'Task data validation failed';
        $exception = new InvalidTaskDataException($message);
        $this->assertSame($message, $exception->getMessage());
    }

    public function testCodeAndPrevious(): void
    {
        $code = 789;
        $previous = new \Exception('Previous exception');
        $exception = new InvalidTaskDataException('Test message', $code, $previous);

        $this->assertSame($code, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }
}
