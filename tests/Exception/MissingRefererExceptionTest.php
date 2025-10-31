<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\HttpRequestTaskBundle\Exception\HttpRequestTaskException;
use Tourze\HttpRequestTaskBundle\Exception\MissingRefererException;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;

/**
 * @internal
 */
#[CoversClass(MissingRefererException::class)]
class MissingRefererExceptionTest extends AbstractExceptionTestCase
{
    public function testExceptionInheritance(): void
    {
        $exception = new MissingRefererException();
        $this->assertInstanceOf(HttpRequestTaskException::class, $exception);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testDefaultMessage(): void
    {
        $exception = new MissingRefererException();
        $this->assertSame('Missing referer header', $exception->getMessage());
    }

    public function testCustomMessage(): void
    {
        $message = 'Custom referer missing message';
        $exception = new MissingRefererException($message);
        $this->assertSame($message, $exception->getMessage());
    }

    public function testCodeAndPrevious(): void
    {
        $code = 123;
        $previous = new \Exception('Previous exception');
        $exception = new MissingRefererException('Test message', $code, $previous);

        $this->assertSame($code, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }
}
