<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Service;

use Tourze\HttpRequestTaskBundle\Entity\HttpRequestLog;

/**
 * 日志结果检查器
 * 提供便捷方法判断日志结果类型
 */
class LogResultChecker
{
    public function isSuccess(HttpRequestLog $log): bool
    {
        return HttpRequestLog::RESULT_SUCCESS === $log->getResult();
    }

    public function isFailure(HttpRequestLog $log): bool
    {
        return HttpRequestLog::RESULT_FAILURE === $log->getResult();
    }

    public function isNetworkError(HttpRequestLog $log): bool
    {
        return HttpRequestLog::RESULT_NETWORK_ERROR === $log->getResult();
    }

    public function isTimeout(HttpRequestLog $log): bool
    {
        return HttpRequestLog::RESULT_TIMEOUT === $log->getResult();
    }
}
