<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Service;

use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;

/**
 * 任务重试计算器
 * 处理重试相关的业务逻辑
 */
class TaskRetryCalculator
{
    public function canRetry(HttpRequestTask $task): bool
    {
        return $task->getAttempts() < $task->getMaxAttempts();
    }

    public function calculateNextRetryDelay(HttpRequestTask $task): int
    {
        if (0 === $task->getAttempts()) {
            return $task->getRetryDelay();
        }

        $delay = (int) ($task->getRetryDelay() * pow($task->getRetryMultiplier(), $task->getAttempts() - 1));
        $jitter = mt_rand(0, (int) ($delay * 0.1));

        return $delay + $jitter;
    }

    public function isScheduledForFuture(HttpRequestTask $task): bool
    {
        $scheduledTime = $task->getScheduledTime();
        if (null === $scheduledTime) {
            return false;
        }

        return $scheduledTime > new \DateTimeImmutable();
    }
}
