<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tourze\HttpRequestTaskBundle\Service\ResponseBodyTruncator;

/**
 * @internal
 */
#[CoversClass(ResponseBodyTruncator::class)]
final class ResponseBodyTruncatorTest extends TestCase
{
    private ResponseBodyTruncator $truncator;

    protected function setUp(): void
    {
        $this->truncator = new ResponseBodyTruncator();
    }

    public function testTruncateReturnsNullForNullInput(): void
    {
        $this->assertNull($this->truncator->truncate(null));
    }

    public function testTruncateDoesNotModifyShortResponse(): void
    {
        $shortResponse = 'Short response';

        $result = $this->truncator->truncate($shortResponse);

        $this->assertEquals($shortResponse, $result);
    }

    public function testTruncateModifiesLongResponse(): void
    {
        $longResponse = str_repeat('x', 15000);

        $result = $this->truncator->truncate($longResponse);

        $this->assertNotNull($result);
        $this->assertStringEndsWith('...[truncated]', $result);
        $this->assertLessThan(mb_strlen($longResponse), mb_strlen($result));
    }

    public function testTruncatePreservesMaxLength(): void
    {
        $longResponse = str_repeat('x', 15000);

        $result = $this->truncator->truncate($longResponse);

        $this->assertNotNull($result);
        // MAX_LENGTH is 10000, plus '...[truncated]' suffix
        $this->assertEquals(10000 + mb_strlen('...[truncated]'), mb_strlen($result));
    }

    public function testTruncateAtBoundary(): void
    {
        // Exactly at MAX_LENGTH (10000)
        $boundaryResponse = str_repeat('x', 10000);

        $result = $this->truncator->truncate($boundaryResponse);

        $this->assertEquals($boundaryResponse, $result);
    }

    public function testTruncateJustOverBoundary(): void
    {
        // Just over MAX_LENGTH (10001)
        $overBoundaryResponse = str_repeat('x', 10001);

        $result = $this->truncator->truncate($overBoundaryResponse);

        $this->assertNotNull($result);
        $this->assertStringEndsWith('...[truncated]', $result);
    }

    public function testTruncateHandlesMultibyteCharacters(): void
    {
        // Test with multibyte characters (Chinese)
        $multibyteResponse = str_repeat('中', 11000);

        $result = $this->truncator->truncate($multibyteResponse);

        $this->assertNotNull($result);
        $this->assertStringEndsWith('...[truncated]', $result);
        // Should be 10000 characters + suffix
        $this->assertEquals(10000 + mb_strlen('...[truncated]'), mb_strlen($result));
    }
}
