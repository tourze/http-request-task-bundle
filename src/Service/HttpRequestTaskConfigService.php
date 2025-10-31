<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Service;

class HttpRequestTaskConfigService
{
    public function __construct()
    {
    }

    public function getDefaultMaxAttempts(): int
    {
        $value = $_ENV['HTTP_TASK_MAX_ATTEMPTS'] ?? 3;

        return is_numeric($value) ? (int) $value : 3;
    }

    public function getDefaultTimeout(): int
    {
        $value = $_ENV['HTTP_TASK_TIMEOUT'] ?? 30;

        return is_numeric($value) ? (int) $value : 30;
    }

    public function getDefaultRetryDelay(): int
    {
        $value = $_ENV['HTTP_TASK_RETRY_DELAY'] ?? 1000;

        return is_numeric($value) ? (int) $value : 1000;
    }

    public function getDefaultRetryMultiplier(): float
    {
        $value = $_ENV['HTTP_TASK_RETRY_MULTIPLIER'] ?? 2.0;

        return is_numeric($value) ? (float) $value : 2.0;
    }

    public function getMessengerTransportName(): string
    {
        $value = $_ENV['HTTP_TASK_TRANSPORT'] ?? 'async';

        return is_string($value) ? $value : 'async';
    }

    public function isRateLimiterEnabled(): bool
    {
        return filter_var($_ENV['HTTP_TASK_RATE_LIMITER_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN);
    }

    public function getRateLimiterDefaultLimit(): int
    {
        $value = $_ENV['HTTP_TASK_RATE_LIMIT'] ?? 10;

        return is_numeric($value) ? (int) $value : 10;
    }
}
