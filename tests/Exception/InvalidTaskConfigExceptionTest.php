<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\HttpRequestTaskBundle\Exception\HttpRequestTaskException;
use Tourze\HttpRequestTaskBundle\Exception\InvalidTaskConfigException;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;

/**
 * @internal
 */
#[CoversClass(InvalidTaskConfigException::class)]
final class InvalidTaskConfigExceptionTest extends AbstractExceptionTestCase
{
    public function testExceptionInheritsFromHttpRequestTaskException(): void
    {
        $exception = new InvalidTaskConfigException();

        $this->assertInstanceOf(HttpRequestTaskException::class, $exception);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testExceptionCanBeCreatedWithMessage(): void
    {
        $message = 'Invalid task configuration: missing URL';
        $exception = new InvalidTaskConfigException($message);

        $this->assertEquals($message, $exception->getMessage());
        $this->assertEquals(0, $exception->getCode());
        $this->assertNull($exception->getPrevious());
    }

    public function testExceptionCanBeCreatedWithAllParameters(): void
    {
        $message = 'Invalid HTTP method';
        $code = 400;
        $previous = new \Exception('Validation error');

        $exception = new InvalidTaskConfigException($message, $code, $previous);

        $this->assertEquals($message, $exception->getMessage());
        $this->assertEquals($code, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testExceptionWithEmptyMessage(): void
    {
        $exception = new InvalidTaskConfigException('');

        $this->assertEquals('', $exception->getMessage());
        $this->assertEquals(0, $exception->getCode());
    }
}
