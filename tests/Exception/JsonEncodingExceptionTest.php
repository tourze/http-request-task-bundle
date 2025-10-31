<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\HttpRequestTaskBundle\Exception\HttpRequestTaskException;
use Tourze\HttpRequestTaskBundle\Exception\JsonEncodingException;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;

/**
 * @internal
 */
#[CoversClass(JsonEncodingException::class)]
class JsonEncodingExceptionTest extends AbstractExceptionTestCase
{
    public function testExceptionInheritance(): void
    {
        $exception = new JsonEncodingException();
        $this->assertInstanceOf(HttpRequestTaskException::class, $exception);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testDefaultMessage(): void
    {
        $exception = new JsonEncodingException();
        $this->assertSame('Failed to encode JSON', $exception->getMessage());
    }

    public function testCustomMessage(): void
    {
        $message = 'Custom JSON encoding error';
        $exception = new JsonEncodingException($message);
        $this->assertSame($message, $exception->getMessage());
    }

    public function testCodeAndPrevious(): void
    {
        $code = 456;
        $previous = new \Exception('Previous exception');
        $exception = new JsonEncodingException('Test message', $code, $previous);

        $this->assertSame($code, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }
}
