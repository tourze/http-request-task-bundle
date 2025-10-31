<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Service;

/**
 * 响应体截断器
 * 防止超大响应体占用过多存储空间
 */
class ResponseBodyTruncator
{
    private const MAX_LENGTH = 10000;

    public function truncate(?string $responseBody): ?string
    {
        if (null === $responseBody) {
            return null;
        }

        if (mb_strlen($responseBody) <= self::MAX_LENGTH) {
            return $responseBody;
        }

        return mb_substr($responseBody, 0, self::MAX_LENGTH) . '...[truncated]';
    }
}
