<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\MessageHandler;

use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestLog;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;
use Tourze\HttpRequestTaskBundle\Exception\TaskNotFoundException;
use Tourze\HttpRequestTaskBundle\Message\HttpRequestTaskMessage;
use Tourze\HttpRequestTaskBundle\MessageHandler\HttpRequestTaskHandler;
use Tourze\HttpRequestTaskBundle\Repository\HttpRequestLogRepository;
use Tourze\HttpRequestTaskBundle\Repository\HttpRequestTaskRepository;
use Tourze\HttpRequestTaskBundle\Service\HttpRequestExecutor;
use Tourze\HttpRequestTaskBundle\Service\HttpRequestTaskConfigService;
use Tourze\HttpRequestTaskBundle\Service\ResponseBodyTruncator;
use Tourze\HttpRequestTaskBundle\Service\TaskRetryCalculator;

/**
 * @internal
 *
 * @phpstan-ignore method.notFound
 */
#[CoversClass(HttpRequestTaskHandler::class)]
final class HttpRequestTaskHandlerTest extends TestCase
{
    private HttpRequestTaskHandler $handler;

    private HttpRequestTaskRepository $taskRepository;

    private HttpRequestExecutor $executor;

    private MessageBusInterface $messageBus;

    private TaskRetryCalculator $retryCalculator;

    protected function setUp(): void
    {
        parent::setUp();

        // No additional setup needed
    }

    private function initializeTest(): void
    {
        $this->taskRepository = $this->createTaskRepository();
        $this->executor = $this->createExecutor();
        $this->messageBus = $this->createMessageBus();
        $this->retryCalculator = $this->createRetryCalculator();

        $this->handler = new HttpRequestTaskHandler(
            $this->taskRepository,
            $this->executor,
            $this->messageBus,
            $this->retryCalculator
        );
    }

    public function testInvokeThrowsExceptionWhenTaskNotFound(): void
    {
        $this->initializeTest();
        $message = new HttpRequestTaskMessage(999);

        $this->setTaskRepositoryFindResult(999, null);

        $this->expectException(TaskNotFoundException::class);
        $this->expectExceptionMessage('Task with ID 999 not found');

        ($this->handler)($message);
    }

    public function testInvokeSkipsCompletedTask(): void
    {
        $this->initializeTest();
        $task = $this->createTask(1, HttpRequestTask::STATUS_COMPLETED);
        $message = new HttpRequestTaskMessage(1);

        $this->setTaskRepositoryFindResult(1, $task);
        $this->setExecutorShouldNotBeCalled();

        ($this->handler)($message);

        // Verify task status remains unchanged
        $this->assertSame(HttpRequestTask::STATUS_COMPLETED, $task->getStatus());
    }

    public function testInvokeSkipsCancelledTask(): void
    {
        $this->initializeTest();
        $task = $this->createTask(1, HttpRequestTask::STATUS_CANCELLED);
        $message = new HttpRequestTaskMessage(1);

        $this->setTaskRepositoryFindResult(1, $task);
        $this->setExecutorShouldNotBeCalled();

        ($this->handler)($message);

        // Verify task status remains unchanged
        $this->assertSame(HttpRequestTask::STATUS_CANCELLED, $task->getStatus());
    }

    public function testInvokeReschedulesTaskForFuture(): void
    {
        $this->initializeTest();
        $task = $this->createTask(1, HttpRequestTask::STATUS_PENDING);
        $task->setScheduledTime(new \DateTimeImmutable('+1 hour'));

        $message = new HttpRequestTaskMessage(1);

        $this->setTaskRepositoryFindResult(1, $task);
        $this->setRetryCalculatorIsScheduledForFuture(true);

        $dispatchedStamps = null;
        $this->setMessageBusDispatchCallback(function ($msg, $stamps) use (&$dispatchedStamps) {
            $dispatchedStamps = $stamps;

            return new Envelope(new HttpRequestTaskMessage(1));
        });

        ($this->handler)($message);

        $this->assertNotNull($dispatchedStamps);
        $this->assertCount(1, $dispatchedStamps);
        $this->assertInstanceOf(DelayStamp::class, $dispatchedStamps[0]);
    }

    public function testInvokeExecutesTaskSuccessfully(): void
    {
        $this->initializeTest();
        $task = $this->createTask(1, HttpRequestTask::STATUS_PENDING);

        $log = $this->createLog(HttpRequestLog::RESULT_SUCCESS);

        $message = new HttpRequestTaskMessage(1);

        $this->setTaskRepositoryFindResult(1, $task);
        $this->setRetryCalculatorIsScheduledForFuture(false);
        $this->setRetryCalculatorCanRetry(false);
        $this->setExecutorResult($log);

        $dispatchCalled = false;
        $this->setMessageBusDispatchCallback(function () use (&$dispatchCalled) {
            $dispatchCalled = true;

            return new Envelope(new \stdClass());
        });

        ($this->handler)($message);

        $this->assertFalse($dispatchCalled); // Should not retry on success
    }

    public function testInvokeSchedulesRetryOnFailedExecution(): void
    {
        $this->initializeTest();
        $task = $this->createTask(1, HttpRequestTask::STATUS_PENDING);

        $log = $this->createLog(HttpRequestLog::RESULT_FAILURE);

        $message = new HttpRequestTaskMessage(1);

        $this->setTaskRepositoryFindResult(1, $task);
        $this->setRetryCalculatorIsScheduledForFuture(false);
        $this->setRetryCalculatorCanRetry(true);
        $this->setRetryCalculatorDelay(5000);
        $this->setExecutorResult($log);

        $dispatchedStamps = null;
        $this->setMessageBusDispatchCallback(function ($msg, $stamps) use (&$dispatchedStamps) {
            $dispatchedStamps = $stamps;

            return new Envelope(new HttpRequestTaskMessage(1));
        });

        ($this->handler)($message);

        $this->assertNotNull($dispatchedStamps);
        $this->assertCount(1, $dispatchedStamps);
        $this->assertInstanceOf(DelayStamp::class, $dispatchedStamps[0]);
    }

    public function testInvokeHandlesExecutionExceptionWithRetry(): void
    {
        $this->initializeTest();
        $task = $this->createTask(1, HttpRequestTask::STATUS_PENDING);

        $message = new HttpRequestTaskMessage(1);

        $this->setTaskRepositoryFindResult(1, $task);
        $this->setRetryCalculatorIsScheduledForFuture(false);
        $this->setRetryCalculatorCanRetry(true);
        $this->setRetryCalculatorDelay(3000);

        $exception = new \RuntimeException('Execution failed');
        $this->setExecutorException($exception);

        $dispatchedStamps = null;
        $this->setMessageBusDispatchCallback(function ($msg, $stamps) use (&$dispatchedStamps) {
            $dispatchedStamps = $stamps;

            return new Envelope(new HttpRequestTaskMessage(1));
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Execution failed');

        ($this->handler)($message);

        // Even though exception is thrown, retry should be scheduled
    }

    public function testInvokeHandlesExecutionExceptionWithoutRetry(): void
    {
        $this->initializeTest();
        $task = $this->createTask(1, HttpRequestTask::STATUS_PENDING);

        $message = new HttpRequestTaskMessage(1);

        $this->setTaskRepositoryFindResult(1, $task);
        $this->setRetryCalculatorIsScheduledForFuture(false);
        $this->setRetryCalculatorCanRetry(false);

        $exception = new \RuntimeException('Execution failed');
        $this->setExecutorException($exception);

        $taskSaved = false;
        $this->setTaskRepositorySaveCallback(function (HttpRequestTask $t, bool $flush) use (&$taskSaved): void {
            $taskSaved = true;
        });

        $dispatchCalled = false;
        $this->setMessageBusDispatchCallback(function () use (&$dispatchCalled) {
            $dispatchCalled = true;

            return new Envelope(new \stdClass());
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Execution failed');

        ($this->handler)($message);
    }

    private function createTask(int $id, string $status): HttpRequestTask
    {
        $task = new HttpRequestTask();
        $task->setStatus($status);

        $reflection = new \ReflectionClass($task);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($task, $id);

        return $task;
    }

    private function createLog(string $result): HttpRequestLog
    {
        $log = new HttpRequestLog();
        $log->setResult($result);

        return $log;
    }

    private function createTaskRepository(): HttpRequestTaskRepository
    {
        $registry = $this->createMock(ManagerRegistry::class);

        return new class($registry) extends HttpRequestTaskRepository {
            private ?HttpRequestTask $findResult = null;

            private int $findId = 0;

            private ?\Closure $saveCallback = null;

            public function __construct(ManagerRegistry $registry)
            {
                parent::__construct($registry);
            }

            public function setFindResult(int $id, ?HttpRequestTask $task): void
            {
                $this->findId = $id;
                $this->findResult = $task;
            }

            public function setSaveCallback(\Closure $callback): void
            {
                $this->saveCallback = $callback;
            }

            public function find(mixed $id, LockMode|int|null $lockMode = null, ?int $lockVersion = null): ?object
            {
                return $this->findId === $id ? $this->findResult : null;
            }

            public function save(HttpRequestTask $entity, bool $flush = false): void
            {
                if (null !== $this->saveCallback) {
                    ($this->saveCallback)($entity, $flush);
                }
            }
        };
    }

    private function createExecutor(): HttpRequestExecutor
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $taskRepository = $this->createMock(HttpRequestTaskRepository::class);
        $logRepository = $this->createMock(HttpRequestLogRepository::class);
        $configService = $this->createMock(HttpRequestTaskConfigService::class);
        $bodyTruncator = $this->createMock(ResponseBodyTruncator::class);
        $retryCalculator = $this->createMock(TaskRetryCalculator::class);

        return new class($httpClient, $taskRepository, $logRepository, $configService, $bodyTruncator, $retryCalculator) extends HttpRequestExecutor {
            private ?HttpRequestLog $result = null;

            private ?\Exception $exception = null;

            private bool $shouldNotBeCalled = false;

            public function __construct(
                HttpClientInterface $httpClient,
                HttpRequestTaskRepository $taskRepository,
                HttpRequestLogRepository $logRepository,
                HttpRequestTaskConfigService $configService,
                ResponseBodyTruncator $bodyTruncator,
                TaskRetryCalculator $retryCalculator,
            ) {
                parent::__construct($httpClient, $taskRepository, $logRepository, $configService, $bodyTruncator, $retryCalculator);
            }

            public function setResult(HttpRequestLog $log): void
            {
                $this->result = $log;
            }

            public function setException(\Exception $exception): void
            {
                $this->exception = $exception;
            }

            public function setShouldNotBeCalled(): void
            {
                $this->shouldNotBeCalled = true;
            }

            public function execute(HttpRequestTask $task): HttpRequestLog
            {
                if ($this->shouldNotBeCalled) {
                    throw new \RuntimeException('Executor should not be called');
                }

                if (null !== $this->exception) {
                    throw $this->exception;
                }

                if (null !== $this->result) {
                    return $this->result;
                }

                throw new \RuntimeException('No result configured');
            }
        };
    }

    private function createMessageBus(): MessageBusInterface
    {
        return new class implements MessageBusInterface {
            private ?\Closure $dispatchCallback = null;

            public function setDispatchCallback(\Closure $callback): void
            {
                $this->dispatchCallback = $callback;
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                if (null !== $this->dispatchCallback) {
                    return ($this->dispatchCallback)($message, $stamps);
                }

                return new Envelope($message);
            }
        };
    }

    private function createRetryCalculator(): TaskRetryCalculator
    {
        return new class extends TaskRetryCalculator {
            private bool $isScheduledForFuture = false;

            private bool $canRetry = false;

            private int $delay = 1000;

            public function setIsScheduledForFuture(bool $value): void
            {
                $this->isScheduledForFuture = $value;
            }

            public function setCanRetry(bool $canRetry): void
            {
                $this->canRetry = $canRetry;
            }

            public function setDelay(int $delay): void
            {
                $this->delay = $delay;
            }

            public function isScheduledForFuture(HttpRequestTask $task): bool
            {
                return $this->isScheduledForFuture;
            }

            public function canRetry(HttpRequestTask $task): bool
            {
                return $this->canRetry;
            }

            public function calculateNextRetryDelay(HttpRequestTask $task): int
            {
                return $this->delay;
            }
        };
    }

    private function setTaskRepositoryFindResult(int $id, ?HttpRequestTask $task): void
    {
        /** @phpstan-ignore method.notFound */
        $this->taskRepository->setFindResult($id, $task);
    }

    private function setTaskRepositorySaveCallback(\Closure $callback): void
    {
        /** @phpstan-ignore method.notFound */
        $this->taskRepository->setSaveCallback($callback);
    }

    private function setExecutorResult(HttpRequestLog $log): void
    {
        /** @phpstan-ignore method.notFound */
        $this->executor->setResult($log);
    }

    private function setExecutorException(\Exception $exception): void
    {
        /** @phpstan-ignore method.notFound */
        $this->executor->setException($exception);
    }

    private function setExecutorShouldNotBeCalled(): void
    {
        /** @phpstan-ignore method.notFound */
        $this->executor->setShouldNotBeCalled();
    }

    private function setMessageBusDispatchCallback(\Closure $callback): void
    {
        /** @phpstan-ignore method.notFound */
        $this->messageBus->setDispatchCallback($callback);
    }

    private function setRetryCalculatorIsScheduledForFuture(bool $value): void
    {
        /** @phpstan-ignore method.notFound */
        $this->retryCalculator->setIsScheduledForFuture($value);
    }

    private function setRetryCalculatorCanRetry(bool $canRetry): void
    {
        /** @phpstan-ignore method.notFound */
        $this->retryCalculator->setCanRetry($canRetry);
    }

    private function setRetryCalculatorDelay(int $delay): void
    {
        /** @phpstan-ignore method.notFound */
        $this->retryCalculator->setDelay($delay);
    }
}
