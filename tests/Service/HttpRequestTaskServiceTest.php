<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\Service;

use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;
use Tourze\HttpRequestTaskBundle\Exception\TaskExecutionException;
use Tourze\HttpRequestTaskBundle\Message\HttpRequestTaskMessage;
use Tourze\HttpRequestTaskBundle\Repository\HttpRequestTaskRepository;
use Tourze\HttpRequestTaskBundle\Service\HttpRequestTaskConfigService;
use Tourze\HttpRequestTaskBundle\Service\HttpRequestTaskService;
use Tourze\HttpRequestTaskBundle\Service\TaskRetryCalculator;
use Tourze\HttpRequestTaskBundle\Service\UuidGenerator;

/**
 * @internal
 *
 * @phpstan-ignore method.notFound, missingType.iterableValue
 */
#[CoversClass(HttpRequestTaskService::class)]
final class HttpRequestTaskServiceTest extends TestCase
{
    private HttpRequestTaskService $service;

    private HttpRequestTaskRepository $taskRepository;

    private MessageBusInterface $messageBus;

    private HttpRequestTaskConfigService $configService;

    private UuidGenerator $uuidGenerator;

    private TaskRetryCalculator $retryCalculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->taskRepository = $this->createTaskRepository();
        $this->messageBus = $this->createMessageBus();
        $this->configService = $this->createConfigService();
        $this->uuidGenerator = $this->createUuidGenerator();
        $this->retryCalculator = $this->createRetryCalculator();

        $this->service = new HttpRequestTaskService(
            $this->taskRepository,
            $this->messageBus,
            $this->configService,
            $this->uuidGenerator,
            $this->retryCalculator
        );
    }

    public function testCreateTaskWithDefaultValues(): void
    {
        $this->setConfigDefaults(3, 30, 60, 2.0);

        $savedTask = null;
        $this->setTaskRepositorySaveCallback(function (HttpRequestTask $task, bool $flush) use (&$savedTask): void {
            $savedTask = $task;
            // Simulate persisting the task
            $reflection = new \ReflectionClass($task);
            $property = $reflection->getProperty('id');
            $property->setAccessible(true);
            $property->setValue($task, 1);
        });

        $this->setMessageBusDispatchCallback(function ($message, $stamps) {
            return new Envelope(new \stdClass());
        });

        $task = $this->service->createTask('https://example.com/api');

        $this->assertInstanceOf(HttpRequestTask::class, $task);
        $this->assertSame('https://example.com/api', $task->getUrl());
        $this->assertSame(HttpRequestTask::METHOD_GET, $task->getMethod());
        $this->assertSame(HttpRequestTask::PRIORITY_NORMAL, $task->getPriority());
    }

    public function testCreateTaskWithCustomValues(): void
    {
        $savedTask = null;
        $this->setTaskRepositorySaveCallback(function (HttpRequestTask $task, bool $flush) use (&$savedTask): void {
            $savedTask = $task;
            $reflection = new \ReflectionClass($task);
            $property = $reflection->getProperty('id');
            $property->setAccessible(true);
            $property->setValue($task, 2);
        });

        $this->setMessageBusDispatchCallback(function ($message, $stamps) {
            return new Envelope(new \stdClass());
        });

        $headers = ['X-API-Key' => 'secret'];
        $body = '{"test": "data"}';
        $options = [
            'max_attempts' => 5,
            'timeout' => 60,
            'retry_delay' => 120,
            'retry_multiplier' => 3.0,
            'metadata' => ['source' => 'test'],
        ];

        $task = $this->service->createTask(
            url: 'https://api.example.com/webhook',
            method: HttpRequestTask::METHOD_POST,
            headers: $headers,
            body: $body,
            contentType: 'application/json',
            priority: HttpRequestTask::PRIORITY_HIGH,
            options: $options
        );

        $this->assertSame('https://api.example.com/webhook', $task->getUrl());
        $this->assertSame(HttpRequestTask::METHOD_POST, $task->getMethod());
        $this->assertSame($headers, $task->getHeaders());
        $this->assertSame($body, $task->getBody());
        $this->assertSame('application/json', $task->getContentType());
        $this->assertSame(HttpRequestTask::PRIORITY_HIGH, $task->getPriority());
        $this->assertSame(5, $task->getMaxAttempts());
        $this->assertSame(60, $task->getTimeout());
        $this->assertSame(120, $task->getRetryDelay());
        $this->assertSame(3.0, $task->getRetryMultiplier());
        $this->assertSame(['source' => 'test'], $task->getMetadata());
    }

    public function testRetryTaskSuccess(): void
    {
        $task = new HttpRequestTask();
        $task->setUrl('https://example.com');
        $task->setMaxAttempts(3);
        $task->setAttempts(1);
        $task->setRetryDelay(1000);
        $task->setRetryMultiplier(2.0);

        $reflection = new \ReflectionClass($task);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($task, 123);

        $this->setRetryCalculatorCanRetry(true);
        $this->setRetryCalculatorDelay(1000);

        $this->setTaskRepositorySaveCallback(function (HttpRequestTask $t, bool $flush): void {
            // Task saved
        });

        $this->setMessageBusDispatchCallback(function ($message, $stamps) {
            return new Envelope(new \stdClass());
        });

        $this->service->retryTask($task);

        $this->assertSame(HttpRequestTask::STATUS_PENDING, $task->getStatus());
    }

    public function testRetryTaskExceedsMaxAttempts(): void
    {
        $task = new HttpRequestTask();
        $task->setMaxAttempts(3);
        $task->setAttempts(3);

        $this->setRetryCalculatorCanRetry(false);

        $this->expectException(TaskExecutionException::class);
        $this->expectExceptionMessage('Task has exceeded maximum retry attempts');

        $this->service->retryTask($task);
    }

    public function testCancelTask(): void
    {
        $task = new HttpRequestTask();
        $task->setStatus(HttpRequestTask::STATUS_PENDING);

        $this->setTaskRepositorySaveCallback(function (HttpRequestTask $t, bool $flush): void {
            // Task saved
        });

        $this->service->cancelTask($task);

        $this->assertSame(HttpRequestTask::STATUS_CANCELLED, $task->getStatus());
    }

    public function testCancelProcessingTaskThrowsException(): void
    {
        $task = new HttpRequestTask();
        $task->setStatus(HttpRequestTask::STATUS_PROCESSING);

        $this->expectException(TaskExecutionException::class);
        $this->expectExceptionMessage('Cannot cancel a task that is currently processing');

        $this->service->cancelTask($task);
    }

    public function testGetTaskStatistics(): void
    {
        $this->setTaskRepositoryCountByStatus([
            HttpRequestTask::STATUS_PENDING => 10,
            HttpRequestTask::STATUS_PROCESSING => 2,
            HttpRequestTask::STATUS_COMPLETED => 50,
            HttpRequestTask::STATUS_FAILED => 3,
            HttpRequestTask::STATUS_CANCELLED => 1,
        ]);

        $stats = $this->service->getTaskStatistics();

        $this->assertSame([
            'pending' => 10,
            'processing' => 2,
            'completed' => 50,
            'failed' => 3,
            'cancelled' => 1,
        ], $stats);
    }

    public function testCleanupOldTasks(): void
    {
        $task1 = new HttpRequestTask();
        $task2 = new HttpRequestTask();

        $this->setTaskRepositoryExpiredTasks([$task1, $task2]);

        $removedTasks = [];
        $this->setTaskRepositoryRemoveCallback(function (HttpRequestTask $task, bool $flush) use (&$removedTasks): void {
            $removedTasks[] = ['task' => $task, 'flush' => $flush];
        });

        $result = $this->service->cleanupOldTasks(30);

        $this->assertSame(2, $result);
        $this->assertCount(2, $removedTasks);
        $this->assertFalse($removedTasks[0]['flush']); // First task shouldn't flush
        $this->assertTrue($removedTasks[1]['flush']); // Last task should flush
    }

    public function testDispatchTask(): void
    {
        $task = new HttpRequestTask();
        $task->setUrl('https://example.com');
        $reflection = new \ReflectionClass($task);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($task, 1);

        $dispatchedMessage = null;
        $this->setMessageBusDispatchCallback(function ($message, $stamps) use (&$dispatchedMessage) {
            $dispatchedMessage = $message;

            return new Envelope(new HttpRequestTaskMessage(1));
        });

        $this->service->dispatchTask($task);

        $this->assertInstanceOf(HttpRequestTaskMessage::class, $dispatchedMessage);
    }

    public function testDispatchTaskWithoutIdThrowsException(): void
    {
        $task = new HttpRequestTask();

        $this->expectException(TaskExecutionException::class);
        $this->expectExceptionMessage('Task must be persisted before dispatching');

        $this->service->dispatchTask($task);
    }

    public function testFindFailedTasks(): void
    {
        $task1 = new HttpRequestTask();
        $task2 = new HttpRequestTask();

        $this->setTaskRepositoryFailedTasks([$task1, $task2]);

        $result = $this->service->findFailedTasks(100);

        $this->assertCount(2, $result);
        $this->assertSame([$task1, $task2], $result);
    }

    public function testFindPendingTasks(): void
    {
        $task1 = new HttpRequestTask();
        $task2 = new HttpRequestTask();

        $this->setTaskRepositoryPendingTasks([$task1, $task2]);

        $result = $this->service->findPendingTasks(100);

        $this->assertCount(2, $result);
        $this->assertSame([$task1, $task2], $result);
    }

    public function testFindTaskById(): void
    {
        $task = new HttpRequestTask();

        $this->setTaskRepositoryFindResult(123, $task);

        $result = $this->service->findTaskById(123);

        $this->assertSame($task, $result);
    }

    public function testFindTaskByUuid(): void
    {
        $task = new HttpRequestTask();
        $uuid = 'test-uuid-123';

        $this->setTaskRepositoryFindByUuidResult($uuid, $task);

        $result = $this->service->findTaskByUuid($uuid);

        $this->assertSame($task, $result);
    }

    public function testFindTasksByStatus(): void
    {
        $task1 = new HttpRequestTask();
        $task2 = new HttpRequestTask();

        $this->setTaskRepositoryFindByStatusResult(HttpRequestTask::STATUS_COMPLETED, 50, [$task1, $task2]);

        $result = $this->service->findTasksByStatus(HttpRequestTask::STATUS_COMPLETED, 50);

        $this->assertCount(2, $result);
        $this->assertSame([$task1, $task2], $result);
    }

    public function testIncrementTaskAttempts(): void
    {
        $task = new HttpRequestTask();
        $task->setAttempts(2);
        $task->setLastAttemptTime(new \DateTimeImmutable('2024-01-01 12:00:00'));

        $this->service->incrementTaskAttempts($task);

        $this->assertSame(3, $task->getAttempts());
        $this->assertInstanceOf(\DateTimeImmutable::class, $task->getLastAttemptTime());
        $this->assertGreaterThan(new \DateTimeImmutable('2024-01-01 12:00:00'), $task->getLastAttemptTime());
    }

    private function createTaskRepository(): HttpRequestTaskRepository
    {
        $registry = $this->createMock(ManagerRegistry::class);

        return new class($registry) extends HttpRequestTaskRepository {
            private ?\Closure $saveCallback = null;

            private ?\Closure $removeCallback = null;

            /** @var array<string, int> */
            private array $countByStatus = [];

            /** @var array<HttpRequestTask> */
            private array $expiredTasks = [];

            /** @var array<HttpRequestTask> */
            private array $failedTasks = [];

            /** @var array<HttpRequestTask> */
            private array $pendingTasks = [];

            private ?HttpRequestTask $findResult = null;

            private int $findId = 0;

            private ?HttpRequestTask $findByUuidResult = null;

            private string $findByUuidValue = '';

            /** @var array<HttpRequestTask> */
            private array $findByStatusResult = [];

            private string $findByStatusValue = '';

            private int $findByStatusLimit = 0;

            public function __construct(ManagerRegistry $registry)
            {
                parent::__construct($registry);
            }

            public function setSaveCallback(\Closure $callback): void
            {
                $this->saveCallback = $callback;
            }

            public function setRemoveCallback(\Closure $callback): void
            {
                $this->removeCallback = $callback;
            }

            /**
             * @param array<string, int> $counts
             */
            public function setCountByStatus(array $counts): void
            {
                $this->countByStatus = $counts;
            }

            /**
             * @param array<HttpRequestTask> $tasks
             */
            public function setExpiredTasks(array $tasks): void
            {
                $this->expiredTasks = $tasks;
            }

            /**
             * @param array<HttpRequestTask> $tasks
             */
            public function setFailedTasks(array $tasks): void
            {
                $this->failedTasks = $tasks;
            }

            /**
             * @param array<HttpRequestTask> $tasks
             */
            public function setPendingTasks(array $tasks): void
            {
                $this->pendingTasks = $tasks;
            }

            public function setFindResult(int $id, ?HttpRequestTask $task): void
            {
                $this->findId = $id;
                $this->findResult = $task;
            }

            public function setFindByUuidResult(string $uuid, ?HttpRequestTask $task): void
            {
                $this->findByUuidValue = $uuid;
                $this->findByUuidResult = $task;
            }

            /**
             * @param array<HttpRequestTask> $tasks
             */
            public function setFindByStatusResult(string $status, int $limit, array $tasks): void
            {
                $this->findByStatusValue = $status;
                $this->findByStatusLimit = $limit;
                $this->findByStatusResult = $tasks;
            }

            public function save(HttpRequestTask $entity, bool $flush = false): void
            {
                if (null !== $this->saveCallback) {
                    ($this->saveCallback)($entity, $flush);
                }
            }

            public function remove(HttpRequestTask $entity, bool $flush = false): void
            {
                if (null !== $this->removeCallback) {
                    ($this->removeCallback)($entity, $flush);
                }
            }

            public function countByStatus(string $status): int
            {
                return $this->countByStatus[$status] ?? 0;
            }

            public function findExpiredTasks(\DateTimeImmutable $before, int $limit = 100): array
            {
                return $this->expiredTasks;
            }

            public function findFailedTasks(int $limit = 100): array
            {
                return $this->failedTasks;
            }

            public function findPendingTasks(int $limit = 100): array
            {
                return $this->pendingTasks;
            }

            public function find(mixed $id, LockMode|int|null $lockMode = null, ?int $lockVersion = null): ?object
            {
                return $this->findId === $id ? $this->findResult : null;
            }

            public function findByUuid(string $uuid): ?HttpRequestTask
            {
                return $this->findByUuidValue === $uuid ? $this->findByUuidResult : null;
            }

            public function findTasksByStatus(string $status, int $limit = 100): array
            {
                return $this->findByStatusValue === $status && $this->findByStatusLimit === $limit ? $this->findByStatusResult : [];
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

    private function createConfigService(): HttpRequestTaskConfigService
    {
        return new class extends HttpRequestTaskConfigService {
            private int $defaultMaxAttempts = 3;

            private int $defaultTimeout = 30;

            private int $defaultRetryDelay = 60;

            private float $defaultRetryMultiplier = 2.0;

            public function __construct()
            {
                parent::__construct();
            }

            public function setDefaults(int $maxAttempts, int $timeout, int $retryDelay, float $retryMultiplier): void
            {
                $this->defaultMaxAttempts = $maxAttempts;
                $this->defaultTimeout = $timeout;
                $this->defaultRetryDelay = $retryDelay;
                $this->defaultRetryMultiplier = $retryMultiplier;
            }

            public function getDefaultMaxAttempts(): int
            {
                return $this->defaultMaxAttempts;
            }

            public function getDefaultTimeout(): int
            {
                return $this->defaultTimeout;
            }

            public function getDefaultRetryDelay(): int
            {
                return $this->defaultRetryDelay;
            }

            public function getDefaultRetryMultiplier(): float
            {
                return $this->defaultRetryMultiplier;
            }
        };
    }

    private function createUuidGenerator(): UuidGenerator
    {
        return new class extends UuidGenerator {
            public function generate(): string
            {
                return 'test-uuid-' . uniqid();
            }
        };
    }

    private function createRetryCalculator(): TaskRetryCalculator
    {
        return new class extends TaskRetryCalculator {
            private bool $canRetry = false;

            private int $delay = 1000;

            public function setCanRetry(bool $canRetry): void
            {
                $this->canRetry = $canRetry;
            }

            public function setDelay(int $delay): void
            {
                $this->delay = $delay;
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

    private function setTaskRepositorySaveCallback(\Closure $callback): void
    {
        /** @phpstan-ignore method.notFound */
        $this->taskRepository->setSaveCallback($callback);
    }

    private function setTaskRepositoryRemoveCallback(\Closure $callback): void
    {
        /** @phpstan-ignore method.notFound */
        $this->taskRepository->setRemoveCallback($callback);
    }

    /**
     * @param array<string, int> $counts
     */
    private function setTaskRepositoryCountByStatus(array $counts): void
    {
        /** @phpstan-ignore method.notFound */
        $this->taskRepository->setCountByStatus($counts);
    }

    /**
     * @param array<HttpRequestTask> $tasks
     */
    private function setTaskRepositoryExpiredTasks(array $tasks): void
    {
        /** @phpstan-ignore method.notFound */
        $this->taskRepository->setExpiredTasks($tasks);
    }

    /**
     * @param array<HttpRequestTask> $tasks
     */
    private function setTaskRepositoryFailedTasks(array $tasks): void
    {
        /** @phpstan-ignore method.notFound */
        $this->taskRepository->setFailedTasks($tasks);
    }

    /**
     * @param array<HttpRequestTask> $tasks
     */
    private function setTaskRepositoryPendingTasks(array $tasks): void
    {
        /** @phpstan-ignore method.notFound */
        $this->taskRepository->setPendingTasks($tasks);
    }

    private function setTaskRepositoryFindResult(int $id, ?HttpRequestTask $task): void
    {
        /** @phpstan-ignore method.notFound */
        $this->taskRepository->setFindResult($id, $task);
    }

    private function setTaskRepositoryFindByUuidResult(string $uuid, ?HttpRequestTask $task): void
    {
        /** @phpstan-ignore method.notFound */
        $this->taskRepository->setFindByUuidResult($uuid, $task);
    }

    /**
     * @param array<HttpRequestTask> $tasks
     */
    private function setTaskRepositoryFindByStatusResult(string $status, int $limit, array $tasks): void
    {
        /** @phpstan-ignore method.notFound */
        $this->taskRepository->setFindByStatusResult($status, $limit, $tasks);
    }

    private function setMessageBusDispatchCallback(\Closure $callback): void
    {
        /** @phpstan-ignore method.notFound */
        $this->messageBus->setDispatchCallback($callback);
    }

    private function setConfigDefaults(int $maxAttempts, int $timeout, int $retryDelay, float $retryMultiplier): void
    {
        /** @phpstan-ignore method.notFound */
        $this->configService->setDefaults($maxAttempts, $timeout, $retryDelay, $retryMultiplier);
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
