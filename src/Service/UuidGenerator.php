<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Service;

/**
 * UUID生成器
 * 从Entity中提取的UUID生成逻辑
 */
class UuidGenerator
{
    public function generate(): string
    {
        if (function_exists('random_bytes')) {
            try {
                $data = random_bytes(16);
                $data[6] = chr(ord($data[6]) & 0x0F | 0x40);
                $data[8] = chr(ord($data[8]) & 0x3F | 0x80);

                return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
            } catch (\Exception) {
                // Fall back to less secure method if random_bytes fails
            }
        }

        // Fallback method (less secure)
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xFFFF),
            mt_rand(0, 0xFFFF),
            mt_rand(0, 0xFFFF),
            mt_rand(0, 0x0FFF) | 0x4000,
            mt_rand(0, 0x3FFF) | 0x8000,
            mt_rand(0, 0xFFFF),
            mt_rand(0, 0xFFFF),
            mt_rand(0, 0xFFFF)
        );
    }
}
