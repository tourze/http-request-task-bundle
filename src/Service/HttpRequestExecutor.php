<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Service;

use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestLog;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;
use Tourze\HttpRequestTaskBundle\Exception\TaskExecutionException;
use Tourze\HttpRequestTaskBundle\Repository\HttpRequestLogRepository;
use Tourze\HttpRequestTaskBundle\Repository\HttpRequestTaskRepository;

class HttpRequestExecutor
{
    private ?RateLimiterFactory $rateLimiterFactory = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly HttpRequestTaskRepository $taskRepository,
        private readonly HttpRequestLogRepository $logRepository,
        private readonly HttpRequestTaskConfigService $configService,
        private readonly ResponseBodyTruncator $bodyTruncator,
        private readonly TaskRetryCalculator $retryCalculator,
    ) {
    }

    public function setRateLimiterFactory(?RateLimiterFactory $rateLimiterFactory): void
    {
        $this->rateLimiterFactory = $rateLimiterFactory;
    }

    public function getRateLimiterFactory(): ?RateLimiterFactory
    {
        return $this->rateLimiterFactory;
    }

    public function execute(HttpRequestTask $task): HttpRequestLog
    {
        $this->validateTaskStatus($task);
        $this->applyRateLimitIfNeeded($task);

        $this->prepareTaskExecution($task);
        $log = $this->createExecutionLog($task);

        $startTime = microtime(true);

        try {
            $this->executeHttpRequest($task, $log);
        } catch (\Exception $e) {
            $this->handleExecutionError($task, $log, $e);
        }

        $this->finalizeExecution($task, $log, $startTime);

        return $log;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRequestOptions(HttpRequestTask $task): array
    {
        $options = [
            'headers' => $task->getHeaders(),
            'timeout' => $task->getTimeout(),
        ];

        if (null !== $task->getBody()) {
            $contentType = $task->getContentType();

            if ('application/json' === $contentType || str_contains($contentType ?? '', 'json')) {
                $options['json'] = json_decode($task->getBody(), true);
            } elseif ('application/x-www-form-urlencoded' === $contentType) {
                parse_str($task->getBody(), $formData);
                $options['body'] = $formData;
            } else {
                $options['body'] = $task->getBody();
                if (null !== $contentType) {
                    $options['headers']['Content-Type'] = $contentType;
                }
            }
        }

        return $options;
    }

    private function shouldApplyRateLimit(HttpRequestTask $task): bool
    {
        if (!$this->configService->isRateLimiterEnabled()) {
            return false;
        }

        return null !== $task->getRateLimitKey() || null !== $task->getRateLimitPerSecond();
    }

    private function applyRateLimit(HttpRequestTask $task): void
    {
        if (null === $this->rateLimiterFactory) {
            return;
        }

        $rateLimitKey = $task->getRateLimitKey() ?? $this->extractDomain($task->getUrl());
        $limiter = $this->rateLimiterFactory->create($rateLimitKey);

        $limit = $limiter->consume(1);

        if (!$limit->isAccepted()) {
            $waitTime = $limit->getRetryAfter()->getTimestamp() - time();
            if ($waitTime > 0) {
                sleep($waitTime);
            }
        }
    }

    private function extractDomain(string $url): string
    {
        $parts = parse_url($url);

        return $parts['host'] ?? 'default';
    }

    private function validateTaskStatus(HttpRequestTask $task): void
    {
        if (HttpRequestTask::STATUS_COMPLETED === $task->getStatus()) {
            throw new TaskExecutionException('Task has already been completed');
        }

        if (HttpRequestTask::STATUS_CANCELLED === $task->getStatus()) {
            throw new TaskExecutionException('Task has been cancelled');
        }
    }

    private function applyRateLimitIfNeeded(HttpRequestTask $task): void
    {
        if ($this->shouldApplyRateLimit($task)) {
            $this->applyRateLimit($task);
        }
    }

    private function prepareTaskExecution(HttpRequestTask $task): void
    {
        $task->setStatus(HttpRequestTask::STATUS_PROCESSING);
        $task->setAttempts($task->getAttempts() + 1);
        $task->setLastAttemptTime(new \DateTimeImmutable());
        $task->setStartedTime(new \DateTimeImmutable());
        $this->taskRepository->save($task, true);
    }

    private function createExecutionLog(HttpRequestTask $task): HttpRequestLog
    {
        $log = new HttpRequestLog();
        $log->setTask($task);
        $log->setAttemptNumber($task->getAttempts());
        $log->setRequestHeaders($task->getHeaders());
        $log->setRequestBody($task->getBody());

        return $log;
    }

    private function executeHttpRequest(HttpRequestTask $task, HttpRequestLog $log): void
    {
        $options = $this->buildRequestOptions($task);

        $response = $this->httpClient->request(
            $task->getMethod(),
            $task->getUrl(),
            $options
        );

        $statusCode = $response->getStatusCode();
        $responseBody = $response->getContent(false);
        $responseHeaders = $response->getHeaders(false);

        $log->setResponseCode($statusCode);
        $log->setResponseBody($this->bodyTruncator->truncate($responseBody));
        $log->setResponseHeaders($responseHeaders);

        $this->updateTaskFromResponse($task, $log, $statusCode, $responseBody, $response);
    }

    private function updateTaskFromResponse(HttpRequestTask $task, HttpRequestLog $log, int $statusCode, string $responseBody, ResponseInterface $response): void
    {
        $task->setLastResponseCode($statusCode);
        $task->setLastResponseBody($responseBody);

        if ($statusCode >= 200 && $statusCode < 300) {
            $log->setResult(HttpRequestLog::RESULT_SUCCESS);
            $task->setStatus(HttpRequestTask::STATUS_COMPLETED);
            $task->setCompletedTime(new \DateTimeImmutable());
        } else {
            $log->setResult(HttpRequestLog::RESULT_FAILURE);
            $httpMethod = $response->getInfo('http_method');
            $url = $response->getInfo('url');
            $log->setErrorMessage(sprintf(
                'HTTP %d: %s %s',
                $statusCode,
                is_string($httpMethod) ? $httpMethod : 'UNKNOWN',
                is_string($url) ? $url : 'UNKNOWN'
            ));
            $this->updateTaskStatusOnFailure($task);
        }
    }

    private function handleExecutionError(HttpRequestTask $task, HttpRequestLog $log, \Exception $e): void
    {
        if ($e instanceof TransportExceptionInterface) {
            $this->handleTransportError($task, $log, $e);
        } elseif ($e instanceof HttpExceptionInterface) {
            $this->handleHttpError($task, $log, $e);
        } else {
            $this->handleGenericError($task, $log, $e);
        }
    }

    private function handleTransportError(HttpRequestTask $task, HttpRequestLog $log, TransportExceptionInterface $e): void
    {
        $log->setResult(str_contains($e->getMessage(), 'timeout')
            ? HttpRequestLog::RESULT_TIMEOUT
            : HttpRequestLog::RESULT_NETWORK_ERROR);
        $log->setErrorMessage($e->getMessage());
        $task->setLastErrorMessage($e->getMessage());
        $this->updateTaskStatusOnFailure($task);
    }

    private function handleHttpError(HttpRequestTask $task, HttpRequestLog $log, HttpExceptionInterface $e): void
    {
        $statusCode = $e->getResponse()->getStatusCode();
        $log->setResponseCode($statusCode);
        $log->setResult(HttpRequestLog::RESULT_FAILURE);

        $errorType = ($statusCode >= 400 && $statusCode < 500) ? 'Client Error' : 'Server Error';
        $log->setErrorMessage("{$errorType} {$statusCode}: {$e->getMessage()}");

        $task->setLastResponseCode($statusCode);
        $task->setLastErrorMessage($e->getMessage());

        // Don't retry 4xx errors except for specific retryable codes
        if ($statusCode >= 400 && $statusCode < 500 && !in_array($statusCode, [408, 429, 409], true)) {
            $task->setStatus(HttpRequestTask::STATUS_FAILED);
            $task->setCompletedTime(new \DateTimeImmutable());
        } else {
            $this->updateTaskStatusOnFailure($task);
        }
    }

    private function handleGenericError(HttpRequestTask $task, HttpRequestLog $log, \Exception $e): void
    {
        $log->setResult(HttpRequestLog::RESULT_FAILURE);
        $log->setErrorMessage($e->getMessage());
        $task->setLastErrorMessage($e->getMessage());
        $this->updateTaskStatusOnFailure($task);
    }

    private function updateTaskStatusOnFailure(HttpRequestTask $task): void
    {
        if ($this->retryCalculator->canRetry($task)) {
            $task->setStatus(HttpRequestTask::STATUS_PENDING);
        } else {
            $task->setStatus(HttpRequestTask::STATUS_FAILED);
            $task->setCompletedTime(new \DateTimeImmutable());
        }
    }

    private function finalizeExecution(HttpRequestTask $task, HttpRequestLog $log, float $startTime): void
    {
        $endTime = microtime(true);
        $responseTime = (int) (($endTime - $startTime) * 1000);
        $log->setResponseTime($responseTime);

        $this->logRepository->save($log, true);
        $this->taskRepository->save($task, true);
    }
}
