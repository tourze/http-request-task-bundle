<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Service;

use Symfony\Component\Console\Input\InputInterface;

/**
 * 命令行输入验证器
 * 提供类型安全的输入参数获取方法
 */
class CommandInputValidator
{
    public function getIntOption(InputInterface $input, string $name, int $default = 0): int
    {
        $value = $input->getOption($name);

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    public function getBoolOption(InputInterface $input, string $name, bool $default = false): bool
    {
        $value = $input->getOption($name);

        if (is_bool($value)) {
            return $value;
        }

        return $default;
    }

    public function getStringOption(InputInterface $input, string $name, string $default = ''): string
    {
        $value = $input->getOption($name);

        if (is_string($value)) {
            return $value;
        }

        return $default;
    }

    public function getStringArgument(InputInterface $input, string $name, string $default = ''): string
    {
        $value = $input->getArgument($name);

        if (is_string($value)) {
            return $value;
        }

        return $default;
    }

    public function getIntArgument(InputInterface $input, string $name, int $default = 0): int
    {
        $value = $input->getArgument($name);

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }
}
