<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\MessageHandler;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestLog;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;
use Tourze\HttpRequestTaskBundle\Exception\InvalidTaskDataException;
use Tourze\HttpRequestTaskBundle\Exception\TaskNotFoundException;
use Tourze\HttpRequestTaskBundle\Message\HttpRequestTaskMessage;
use Tourze\HttpRequestTaskBundle\Repository\HttpRequestTaskRepository;
use Tourze\HttpRequestTaskBundle\Service\HttpRequestExecutor;
use Tourze\HttpRequestTaskBundle\Service\TaskRetryCalculator;

#[AsMessageHandler]
class HttpRequestTaskHandler
{
    public function __construct(
        private readonly HttpRequestTaskRepository $taskRepository,
        private readonly HttpRequestExecutor $executor,
        private readonly MessageBusInterface $messageBus,
        private readonly TaskRetryCalculator $retryCalculator,
    ) {
    }

    public function __invoke(HttpRequestTaskMessage $message): void
    {
        $task = $this->taskRepository->find($message->getTaskId());

        if (null === $task) {
            throw new TaskNotFoundException(sprintf('Task with ID %d not found', $message->getTaskId()));
        }

        if (HttpRequestTask::STATUS_COMPLETED === $task->getStatus()) {
            return;
        }

        if (HttpRequestTask::STATUS_CANCELLED === $task->getStatus()) {
            return;
        }

        if ($this->retryCalculator->isScheduledForFuture($task)) {
            $this->rescheduleTask($task);

            return;
        }

        try {
            $log = $this->executor->execute($task);

            if (HttpRequestLog::RESULT_SUCCESS !== $log->getResult() && $this->retryCalculator->canRetry($task)) {
                $this->scheduleRetry($task);
            }
        } catch (\Exception $e) {
            if ($this->retryCalculator->canRetry($task)) {
                $this->scheduleRetry($task);
            } else {
                $task->setStatus(HttpRequestTask::STATUS_FAILED);
                $task->setLastErrorMessage($e->getMessage());
                $task->setCompletedTime(new \DateTimeImmutable());
                $this->taskRepository->save($task, true);
            }

            throw $e;
        }
    }

    private function scheduleRetry(HttpRequestTask $task): void
    {
        $taskId = $task->getId();
        if (null === $taskId) {
            throw new InvalidTaskDataException('Task must be persisted before scheduling retry');
        }

        $delay = $this->retryCalculator->calculateNextRetryDelay($task);
        $message = new HttpRequestTaskMessage($taskId);
        $stamps = [new DelayStamp($delay)];

        $this->messageBus->dispatch($message, $stamps);
    }

    private function rescheduleTask(HttpRequestTask $task): void
    {
        $scheduledTime = $task->getScheduledTime();
        if (null === $scheduledTime) {
            $this->executor->execute($task);

            return;
        }

        $delay = $scheduledTime->getTimestamp() - time();

        if ($delay <= 0) {
            $this->executor->execute($task);

            return;
        }

        $taskId = $task->getId();
        if (null === $taskId) {
            throw new InvalidTaskDataException('Task must be persisted before rescheduling');
        }

        $message = new HttpRequestTaskMessage($taskId);
        $stamps = [new DelayStamp($delay * 1000)];

        $this->messageBus->dispatch($message, $stamps);
    }
}
