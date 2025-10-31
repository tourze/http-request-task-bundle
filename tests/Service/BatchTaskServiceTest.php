<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\Service;

use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;
use Tourze\HttpRequestTaskBundle\Repository\HttpRequestTaskRepository;
use Tourze\HttpRequestTaskBundle\Service\BatchTaskService;
use Tourze\HttpRequestTaskBundle\Service\HttpRequestTaskConfigService;
use Tourze\HttpRequestTaskBundle\Service\HttpRequestTaskService;
use Tourze\HttpRequestTaskBundle\Service\TaskRetryCalculator;
use Tourze\HttpRequestTaskBundle\Service\UuidGenerator;

/**
 * @internal
 *
 * @phpstan-ignore method.notFound
 */
#[CoversClass(BatchTaskService::class)]
final class BatchTaskServiceTest extends TestCase
{
    private BatchTaskService $service;

    private HttpRequestTaskService $taskService;

    protected function setUp(): void
    {
        parent::setUp();

        // 创建真实的 HttpRequestTaskService，但Mock所有依赖
        $taskRepository = $this->createTaskRepository();
        $messageBus = $this->createMessageBus();
        $configService = $this->createConfigService();
        $uuidGenerator = $this->createUuidGenerator();
        $retryCalculator = $this->createRetryCalculator();

        $this->taskService = new HttpRequestTaskService(
            $taskRepository,
            $messageBus,
            $configService,
            $uuidGenerator,
            $retryCalculator
        );

        $this->service = new BatchTaskService($this->taskService);
    }

    public function testCreateBatch(): void
    {
        $tasks = [
            [
                'url' => 'https://api1.example.com',
                'method' => 'GET',
            ],
            [
                'url' => 'https://api2.example.com',
                'method' => 'POST',
                'body' => '{"test": "data"}',
                'contentType' => 'application/json',
            ],
        ];

        $createdTasks = $this->service->createBatch($tasks);

        $this->assertCount(2, $createdTasks);
        $this->assertInstanceOf(HttpRequestTask::class, $createdTasks[0]);
        $this->assertInstanceOf(HttpRequestTask::class, $createdTasks[1]);
        $this->assertSame('https://api1.example.com', $createdTasks[0]->getUrl());
        $this->assertSame('https://api2.example.com', $createdTasks[1]->getUrl());
    }

    public function testCreateFromUrls(): void
    {
        $urls = [
            'https://example1.com',
            'https://example2.com',
            'https://example3.com',
        ];

        $commonOptions = [
            'method' => 'GET',
            'priority' => HttpRequestTask::PRIORITY_HIGH,
        ];

        $createdTasks = $this->service->createFromUrls($urls, $commonOptions);

        $this->assertCount(3, $createdTasks);
        $this->assertInstanceOf(HttpRequestTask::class, $createdTasks[0]);
        $this->assertInstanceOf(HttpRequestTask::class, $createdTasks[1]);
        $this->assertInstanceOf(HttpRequestTask::class, $createdTasks[2]);
    }

    public function testCreateApiCalls(): void
    {
        $endpoints = [
            [
                'url' => 'https://api.example.com/users',
                'data' => ['name' => 'John', 'email' => 'john@example.com'],
            ],
            [
                'url' => 'https://api.example.com/orders',
                'data' => ['product' => 'Widget', 'quantity' => 5],
            ],
        ];

        $createdTasks = $this->service->createApiCalls($endpoints);

        $this->assertCount(2, $createdTasks);
        $this->assertInstanceOf(HttpRequestTask::class, $createdTasks[0]);
        $this->assertInstanceOf(HttpRequestTask::class, $createdTasks[1]);
    }

    public function testCreateResourceFetches(): void
    {
        $baseUrl = 'https://api.example.com';
        $resources = [
            '/users' => ['page' => 1, 'limit' => 10],
            '/products' => ['category' => 'electronics'],
            '/orders' => [],
        ];

        $createdTasks = $this->service->createResourceFetches($baseUrl, $resources);

        $this->assertCount(3, $createdTasks);
        $this->assertInstanceOf(HttpRequestTask::class, $createdTasks[0]);
        $this->assertInstanceOf(HttpRequestTask::class, $createdTasks[1]);
        $this->assertInstanceOf(HttpRequestTask::class, $createdTasks[2]);
    }

    public function testCreateWebhookEvents(): void
    {
        $webhookUrl = 'https://webhook.example.com/events';
        $events = [
            ['type' => 'user.created', 'id' => '123', 'data' => ['name' => 'John']],
            ['type' => 'order.completed', 'id' => '456', 'data' => ['total' => 100]],
        ];

        $createdTasks = $this->service->createWebhookEvents($webhookUrl, $events);

        $this->assertCount(2, $createdTasks);
        $this->assertInstanceOf(HttpRequestTask::class, $createdTasks[0]);
        $this->assertInstanceOf(HttpRequestTask::class, $createdTasks[1]);
    }

    public function testCreateScheduledBatch(): void
    {
        $startTime = new \DateTimeImmutable('2024-01-01 10:00:00');
        $count = 3;
        $intervalSeconds = 300;
        $taskTemplate = [
            'url' => 'https://api.example.com/cron',
            'method' => 'POST',
        ];

        $createdTasks = $this->service->createScheduledBatch(
            $startTime,
            $count,
            $intervalSeconds,
            $taskTemplate
        );

        $this->assertCount(3, $createdTasks);
        $this->assertInstanceOf(HttpRequestTask::class, $createdTasks[0]);
        $this->assertInstanceOf(HttpRequestTask::class, $createdTasks[1]);
        $this->assertInstanceOf(HttpRequestTask::class, $createdTasks[2]);
    }

    private function createTaskRepository(): HttpRequestTaskRepository
    {
        $registry = $this->createMock(ManagerRegistry::class);

        return new class($registry) extends HttpRequestTaskRepository {
            private int $nextId = 1;

            public function __construct(ManagerRegistry $registry)
            {
                parent::__construct($registry);
            }

            public function save(HttpRequestTask $entity, bool $flush = false): void
            {
                // Simulate setting the ID
                $reflection = new \ReflectionClass($entity);
                $property = $reflection->getProperty('id');
                $property->setAccessible(true);
                $property->setValue($entity, $this->nextId++);
            }
        };
    }

    private function createMessageBus(): MessageBusInterface
    {
        return new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return new Envelope($message);
            }
        };
    }

    private function createConfigService(): HttpRequestTaskConfigService
    {
        return new class extends HttpRequestTaskConfigService {
            public function __construct()
            {
                parent::__construct();
            }

            public function getDefaultMaxAttempts(): int
            {
                return 3;
            }

            public function getDefaultTimeout(): int
            {
                return 30;
            }

            public function getDefaultRetryDelay(): int
            {
                return 60;
            }

            public function getDefaultRetryMultiplier(): float
            {
                return 2.0;
            }
        };
    }

    private function createUuidGenerator(): UuidGenerator
    {
        return new class extends UuidGenerator {
            private int $counter = 0;

            public function generate(): string
            {
                return 'test-uuid-' . $this->counter++;
            }
        };
    }

    private function createRetryCalculator(): TaskRetryCalculator
    {
        return new class extends TaskRetryCalculator {
            public function canRetry(HttpRequestTask $task): bool
            {
                return false;
            }

            public function calculateNextRetryDelay(HttpRequestTask $task): int
            {
                return 1000;
            }
        };
    }
}
