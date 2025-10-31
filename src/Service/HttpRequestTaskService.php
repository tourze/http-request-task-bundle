<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Service;

use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;
use Tourze\HttpRequestTaskBundle\Exception\TaskExecutionException;
use Tourze\HttpRequestTaskBundle\Message\HttpRequestTaskMessage;
use Tourze\HttpRequestTaskBundle\Repository\HttpRequestTaskRepository;

readonly class HttpRequestTaskService
{
    public function __construct(
        private HttpRequestTaskRepository $taskRepository,
        private MessageBusInterface $messageBus,
        private HttpRequestTaskConfigService $configService,
        private UuidGenerator $uuidGenerator,
        private TaskRetryCalculator $retryCalculator,
    ) {
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $options
     */
    public function createTask(
        string $url,
        string $method = HttpRequestTask::METHOD_GET,
        array $headers = [],
        ?string $body = null,
        ?string $contentType = null,
        int $priority = HttpRequestTask::PRIORITY_NORMAL,
        array $options = [],
    ): HttpRequestTask {
        $task = new HttpRequestTask();
        $task->setUuid($this->uuidGenerator->generate());
        $task->setUrl($url);
        $task->setMethod($method);
        $task->setHeaders($headers);
        $task->setBody($body);
        $task->setContentType($contentType);
        $task->setPriority($priority);

        $this->applyConfigurationOptions($task, $options);
        $this->applyOptionalOptions($task, $options);

        $this->taskRepository->save($task, true);
        $this->dispatchTask($task);

        return $task;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function applyConfigurationOptions(HttpRequestTask $task, array $options): void
    {
        $maxAttempts = $options['max_attempts'] ?? $this->configService->getDefaultMaxAttempts();
        $task->setMaxAttempts(is_int($maxAttempts) ? $maxAttempts : $this->configService->getDefaultMaxAttempts());

        $timeout = $options['timeout'] ?? $this->configService->getDefaultTimeout();
        $task->setTimeout(is_int($timeout) ? $timeout : $this->configService->getDefaultTimeout());

        $retryDelay = $options['retry_delay'] ?? $this->configService->getDefaultRetryDelay();
        $task->setRetryDelay(is_int($retryDelay) ? $retryDelay : $this->configService->getDefaultRetryDelay());

        $retryMultiplier = $options['retry_multiplier'] ?? $this->configService->getDefaultRetryMultiplier();
        $task->setRetryMultiplier(is_float($retryMultiplier) || is_int($retryMultiplier) ? (float) $retryMultiplier : $this->configService->getDefaultRetryMultiplier());
    }

    /**
     * @param array<string, mixed> $options
     */
    private function applyOptionalOptions(HttpRequestTask $task, array $options): void
    {
        if (isset($options['scheduled_at']) && $options['scheduled_at'] instanceof \DateTimeImmutable) {
            $task->setScheduledTime($options['scheduled_at']);
        }
        if (isset($options['metadata']) && is_array($options['metadata'])) {
            /** @var array<string, mixed> $metadata */
            $metadata = $options['metadata'];
            $task->setMetadata($metadata);
        }
        if (isset($options['rate_limit_key']) && is_string($options['rate_limit_key'])) {
            $task->setRateLimitKey($options['rate_limit_key']);
        }
        if (isset($options['rate_limit_per_second']) && is_int($options['rate_limit_per_second'])) {
            $task->setRateLimitPerSecond($options['rate_limit_per_second']);
        }
    }

    public function dispatchTask(HttpRequestTask $task): void
    {
        if (null === $task->getId()) {
            throw new TaskExecutionException('Task must be persisted before dispatching');
        }

        $message = new HttpRequestTaskMessage($task->getId());
        $stamps = [];

        if ($this->retryCalculator->isScheduledForFuture($task)) {
            $scheduledTime = $task->getScheduledTime();
            if (null !== $scheduledTime) {
                $delay = $scheduledTime->getTimestamp() - time();
                if ($delay > 0) {
                    $stamps[] = new DelayStamp($delay * 1000);
                }
            }
        }

        $this->messageBus->dispatch($message, $stamps);
    }

    public function retryTask(HttpRequestTask $task): void
    {
        if (!$this->retryCalculator->canRetry($task)) {
            throw new TaskExecutionException('Task has exceeded maximum retry attempts');
        }

        $delay = $this->retryCalculator->calculateNextRetryDelay($task);
        $task->setStatus(HttpRequestTask::STATUS_PENDING);
        $this->taskRepository->save($task, true);

        $taskId = $task->getId();
        if (null === $taskId) {
            throw new TaskExecutionException('Task must be persisted before retry');
        }

        $message = new HttpRequestTaskMessage($taskId);
        $stamps = [new DelayStamp($delay)];

        $this->messageBus->dispatch($message, $stamps);
    }

    public function cancelTask(HttpRequestTask $task): void
    {
        if (HttpRequestTask::STATUS_PROCESSING === $task->getStatus()) {
            throw new TaskExecutionException('Cannot cancel a task that is currently processing');
        }

        $task->setStatus(HttpRequestTask::STATUS_CANCELLED);
        $this->taskRepository->save($task, true);
    }

    public function findTaskById(int $id): ?HttpRequestTask
    {
        return $this->taskRepository->find($id);
    }

    public function findTaskByUuid(string $uuid): ?HttpRequestTask
    {
        return $this->taskRepository->findByUuid($uuid);
    }

    /**
     * @return HttpRequestTask[]
     */
    public function findPendingTasks(int $limit = 100): array
    {
        return $this->taskRepository->findPendingTasks($limit);
    }

    /**
     * @return HttpRequestTask[]
     */
    public function findFailedTasks(int $limit = 100): array
    {
        return $this->taskRepository->findFailedTasks($limit);
    }

    /**
     * @return HttpRequestTask[]
     */
    public function findTasksByStatus(string $status, int $limit = 100): array
    {
        return $this->taskRepository->findTasksByStatus($status, $limit);
    }

    /**
     * @return array<string, int>
     */
    public function getTaskStatistics(): array
    {
        return [
            'pending' => $this->taskRepository->countByStatus(HttpRequestTask::STATUS_PENDING),
            'processing' => $this->taskRepository->countByStatus(HttpRequestTask::STATUS_PROCESSING),
            'completed' => $this->taskRepository->countByStatus(HttpRequestTask::STATUS_COMPLETED),
            'failed' => $this->taskRepository->countByStatus(HttpRequestTask::STATUS_FAILED),
            'cancelled' => $this->taskRepository->countByStatus(HttpRequestTask::STATUS_CANCELLED),
        ];
    }

    public function cleanupOldTasks(int $days = 90): int
    {
        $before = new \DateTimeImmutable("-{$days} days");
        $tasks = $this->taskRepository->findExpiredTasks($before);

        $count = 0;
        foreach ($tasks as $index => $task) {
            $flush = ($index === count($tasks) - 1);
            $this->taskRepository->remove($task, $flush);
            ++$count;
        }

        return $count;
    }

    public function incrementTaskAttempts(HttpRequestTask $task): void
    {
        $task->setAttempts($task->getAttempts() + 1);
        $task->setLastAttemptTime(new \DateTimeImmutable());
    }
}
