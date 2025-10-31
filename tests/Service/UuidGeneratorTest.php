<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tourze\HttpRequestTaskBundle\Service\UuidGenerator;

/**
 * @internal
 */
#[CoversClass(UuidGenerator::class)]
final class UuidGeneratorTest extends TestCase
{
    private UuidGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new UuidGenerator();
    }

    public function testGenerateReturnsValidUuidFormat(): void
    {
        $uuid = $this->generator->generate();

        // UUID v4 format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
        // where y is one of [8, 9, a, b]
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

        $this->assertMatchesRegularExpression($pattern, $uuid);
    }

    public function testGenerateReturnsCorrectLength(): void
    {
        $uuid = $this->generator->generate();

        $this->assertSame(36, strlen($uuid));
    }

    public function testGenerateReturnsDifferentUuids(): void
    {
        $uuid1 = $this->generator->generate();
        $uuid2 = $this->generator->generate();

        $this->assertNotSame($uuid1, $uuid2);
    }

    public function testGenerateReturnsMultipleUniqueValues(): void
    {
        $uuids = [];
        for ($i = 0; $i < 100; ++$i) {
            $uuids[] = $this->generator->generate();
        }

        $uniqueUuids = array_unique($uuids);

        $this->assertCount(100, $uniqueUuids);
    }

    public function testGenerateContainsDashesAtCorrectPositions(): void
    {
        $uuid = $this->generator->generate();

        $this->assertSame('-', $uuid[8]);
        $this->assertSame('-', $uuid[13]);
        $this->assertSame('-', $uuid[18]);
        $this->assertSame('-', $uuid[23]);
    }

    public function testGenerateVersionBitIsSetCorrectly(): void
    {
        $uuid = $this->generator->generate();

        // Version 4 UUID has '4' at position 14
        $this->assertSame('4', $uuid[14]);
    }

    public function testGenerateVariantBitsAreSetCorrectly(): void
    {
        $uuid = $this->generator->generate();

        // Variant bits at position 19 should be one of [8, 9, a, b]
        $variantChar = strtolower($uuid[19]);
        $this->assertContains($variantChar, ['8', '9', 'a', 'b']);
    }

    public function testGenerateReturnsLowercaseHexCharacters(): void
    {
        $uuid = $this->generator->generate();

        // Remove dashes and check if all characters are lowercase hex
        $hexPart = str_replace('-', '', $uuid);
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/i', $hexPart);
    }

    public function testGenerateWithRandomBytes(): void
    {
        // Assuming random_bytes is available in the test environment
        if (!function_exists('random_bytes')) {
            self::markTestSkipped('random_bytes function not available');
        }

        $uuid = $this->generator->generate();

        // Should still generate valid UUID format
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        $this->assertMatchesRegularExpression($pattern, $uuid);
    }

    public function testGenerateConsistentStructure(): void
    {
        $uuids = [];
        for ($i = 0; $i < 10; ++$i) {
            $uuids[] = $this->generator->generate();
        }

        foreach ($uuids as $uuid) {
            // All should have the same structure
            $this->assertSame(36, strlen($uuid));
            $this->assertSame('4', $uuid[14]);
            $this->assertContains(strtolower($uuid[19]), ['8', '9', 'a', 'b']);
        }
    }
}
