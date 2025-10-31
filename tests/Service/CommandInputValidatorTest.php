<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputInterface;
use Tourze\HttpRequestTaskBundle\Service\CommandInputValidator;

/**
 * @internal
 */
#[CoversClass(CommandInputValidator::class)]
final class CommandInputValidatorTest extends TestCase
{
    private CommandInputValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new CommandInputValidator();
    }

    #[DataProvider('intOptionProvider')]
    public function testGetIntOption(mixed $value, int $default, int $expected): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->willReturn($value);

        $result = $this->validator->getIntOption($input, 'test', $default);

        $this->assertSame($expected, $result);
    }

    /**
     * @return array<string, array{mixed, int, int}>
     */
    public static function intOptionProvider(): array
    {
        return [
            'integer value' => [42, 0, 42],
            'numeric string' => ['123', 0, 123],
            'non-numeric string returns default' => ['abc', 10, 10],
            'null returns default' => [null, 5, 5],
            'float string converted to int' => ['3.14', 0, 3],
            'zero value' => [0, 10, 0],
        ];
    }

    #[DataProvider('boolOptionProvider')]
    public function testGetBoolOption(mixed $value, bool $default, bool $expected): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->willReturn($value);

        $result = $this->validator->getBoolOption($input, 'test', $default);

        $this->assertSame($expected, $result);
    }

    /**
     * @return array<string, array{mixed, bool, bool}>
     */
    public static function boolOptionProvider(): array
    {
        return [
            'true value' => [true, false, true],
            'false value' => [false, true, false],
            'non-bool returns default (null)' => [null, true, true],
            'non-bool returns default (string)' => ['yes', false, false],
            'non-bool returns default (int)' => [1, false, false],
        ];
    }

    #[DataProvider('stringOptionProvider')]
    public function testGetStringOption(mixed $value, string $default, string $expected): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->willReturn($value);

        $result = $this->validator->getStringOption($input, 'test', $default);

        $this->assertSame($expected, $result);
    }

    /**
     * @return array<string, array{mixed, string, string}>
     */
    public static function stringOptionProvider(): array
    {
        return [
            'string value' => ['hello', 'default', 'hello'],
            'empty string' => ['', 'default', ''],
            'null returns default' => [null, 'default', 'default'],
            'int returns default' => [123, 'default', 'default'],
            'array returns default' => [['a'], 'default', 'default'],
        ];
    }

    #[DataProvider('stringArgumentProvider')]
    public function testGetStringArgument(mixed $value, string $default, string $expected): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getArgument')->willReturn($value);

        $result = $this->validator->getStringArgument($input, 'test', $default);

        $this->assertSame($expected, $result);
    }

    /**
     * @return array<string, array{mixed, string, string}>
     */
    public static function stringArgumentProvider(): array
    {
        return [
            'string value' => ['world', 'default', 'world'],
            'empty string' => ['', 'default', ''],
            'null returns default' => [null, 'default', 'default'],
            'bool returns default' => [true, 'default', 'default'],
        ];
    }

    #[DataProvider('intArgumentProvider')]
    public function testGetIntArgument(mixed $value, int $default, int $expected): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getArgument')->willReturn($value);

        $result = $this->validator->getIntArgument($input, 'test', $default);

        $this->assertSame($expected, $result);
    }

    /**
     * @return array<string, array{mixed, int, int}>
     */
    public static function intArgumentProvider(): array
    {
        return [
            'integer value' => [100, 0, 100],
            'numeric string' => ['500', 0, 500],
            'non-numeric returns default' => ['text', 99, 99],
            'null returns default' => [null, 77, 77],
            'negative number' => [-50, 0, -50],
        ];
    }

    public function testGetIntOptionWithoutDefault(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->willReturn('not-a-number');

        $result = $this->validator->getIntOption($input, 'test');

        $this->assertSame(0, $result);
    }

    public function testGetBoolOptionWithoutDefault(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->willReturn('not-a-bool');

        $result = $this->validator->getBoolOption($input, 'test');

        $this->assertFalse($result);
    }

    public function testGetStringOptionWithoutDefault(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->willReturn(123);

        $result = $this->validator->getStringOption($input, 'test');

        $this->assertSame('', $result);
    }

    public function testGetStringArgumentWithoutDefault(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getArgument')->willReturn(null);

        $result = $this->validator->getStringArgument($input, 'test');

        $this->assertSame('', $result);
    }

    public function testGetIntArgumentWithoutDefault(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getArgument')->willReturn(null);

        $result = $this->validator->getIntArgument($input, 'test');

        $this->assertSame(0, $result);
    }
}
