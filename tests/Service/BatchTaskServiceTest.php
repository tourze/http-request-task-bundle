<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;
use Tourze\HttpRequestTaskBundle\Repository\HttpRequestTaskRepository;
use Tourze\HttpRequestTaskBundle\Service\BatchTaskService;
use Tourze\PHPUnitSymfonyKernelTest\AbstractIntegrationTestCase;

/**
 * @internal
 */
#[CoversClass(BatchTaskService::class)]
#[RunTestsInSeparateProcesses]
final class BatchTaskServiceTest extends AbstractIntegrationTestCase
{
    private BatchTaskService $service;

    private HttpRequestTaskRepository $repository;

    protected function onSetUp(): void
    {
        $this->service = self::getService(BatchTaskService::class);
        $this->repository = self::getService(HttpRequestTaskRepository::class);
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
        $this->assertSame('GET', $createdTasks[0]->getMethod());
        $this->assertSame('https://api2.example.com', $createdTasks[1]->getUrl());
        $this->assertSame('POST', $createdTasks[1]->getMethod());
        $this->assertSame('{"test": "data"}', $createdTasks[1]->getBody());
        $this->assertSame('application/json', $createdTasks[1]->getContentType());

        // Verify tasks were persisted to database
        $this->assertNotNull($createdTasks[0]->getId());
        $this->assertNotNull($createdTasks[1]->getId());

        $persistedTask1 = $this->repository->find($createdTasks[0]->getId());
        $persistedTask2 = $this->repository->find($createdTasks[1]->getId());

        $this->assertInstanceOf(HttpRequestTask::class, $persistedTask1);
        $this->assertInstanceOf(HttpRequestTask::class, $persistedTask2);
        $this->assertSame('https://api1.example.com', $persistedTask1->getUrl());
        $this->assertSame('https://api2.example.com', $persistedTask2->getUrl());
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

        foreach ($createdTasks as $index => $task) {
            $this->assertInstanceOf(HttpRequestTask::class, $task);
            $this->assertSame($urls[$index], $task->getUrl());
            $this->assertSame('GET', $task->getMethod());
            $this->assertSame(HttpRequestTask::PRIORITY_HIGH, $task->getPriority());
            $this->assertNotNull($task->getId());

            // Verify task was persisted
            $persisted = $this->repository->find($task->getId());
            $this->assertInstanceOf(HttpRequestTask::class, $persisted);
            $this->assertSame($urls[$index], $persisted->getUrl());
        }
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
        $this->assertSame('https://api.example.com/users', $createdTasks[0]->getUrl());
        $this->assertSame('POST', $createdTasks[0]->getMethod());
        $this->assertSame('application/json', $createdTasks[0]->getContentType());
        $this->assertSame('{"name":"John","email":"john@example.com"}', $createdTasks[0]->getBody());

        $this->assertInstanceOf(HttpRequestTask::class, $createdTasks[1]);
        $this->assertSame('https://api.example.com/orders', $createdTasks[1]->getUrl());
        $this->assertSame('POST', $createdTasks[1]->getMethod());
        $this->assertSame('application/json', $createdTasks[1]->getContentType());
        $this->assertSame('{"product":"Widget","quantity":5}', $createdTasks[1]->getBody());

        // Verify tasks were persisted
        foreach ($createdTasks as $task) {
            $this->assertNotNull($task->getId());
            $persisted = $this->repository->find($task->getId());
            $this->assertInstanceOf(HttpRequestTask::class, $persisted);
        }
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
        $this->assertStringContainsString('https://api.example.com/users', $createdTasks[0]->getUrl());
        $this->assertStringContainsString('page=1', $createdTasks[0]->getUrl());
        $this->assertStringContainsString('limit=10', $createdTasks[0]->getUrl());

        $this->assertInstanceOf(HttpRequestTask::class, $createdTasks[1]);
        $this->assertStringContainsString('https://api.example.com/products', $createdTasks[1]->getUrl());
        $this->assertStringContainsString('category=electronics', $createdTasks[1]->getUrl());

        $this->assertInstanceOf(HttpRequestTask::class, $createdTasks[2]);
        $this->assertSame('https://api.example.com/orders', $createdTasks[2]->getUrl());

        // Verify all tasks were persisted
        foreach ($createdTasks as $task) {
            $this->assertNotNull($task->getId());
            $persisted = $this->repository->find($task->getId());
            $this->assertInstanceOf(HttpRequestTask::class, $persisted);
        }
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
        $this->assertSame($webhookUrl, $createdTasks[0]->getUrl());
        $this->assertSame('POST', $createdTasks[0]->getMethod());
        $this->assertSame('application/json', $createdTasks[0]->getContentType());
        $this->assertSame('{"type":"user.created","id":"123","data":{"name":"John"}}', $createdTasks[0]->getBody());

        $headers1 = $createdTasks[0]->getHeaders();
        $this->assertArrayHasKey('X-Event-Type', $headers1);
        $this->assertSame('user.created', $headers1['X-Event-Type']);
        $this->assertArrayHasKey('X-Event-Id', $headers1);
        $this->assertSame('123', $headers1['X-Event-Id']);

        $this->assertInstanceOf(HttpRequestTask::class, $createdTasks[1]);
        $this->assertSame($webhookUrl, $createdTasks[1]->getUrl());
        $this->assertSame('POST', $createdTasks[1]->getMethod());
        $this->assertSame('{"type":"order.completed","id":"456","data":{"total":100}}', $createdTasks[1]->getBody());

        $headers2 = $createdTasks[1]->getHeaders();
        $this->assertArrayHasKey('X-Event-Type', $headers2);
        $this->assertSame('order.completed', $headers2['X-Event-Type']);
        $this->assertArrayHasKey('X-Event-Id', $headers2);
        $this->assertSame('456', $headers2['X-Event-Id']);

        // Verify tasks were persisted
        foreach ($createdTasks as $task) {
            $this->assertNotNull($task->getId());
            $persisted = $this->repository->find($task->getId());
            $this->assertInstanceOf(HttpRequestTask::class, $persisted);
        }
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

        foreach ($createdTasks as $index => $task) {
            $this->assertInstanceOf(HttpRequestTask::class, $task);
            $this->assertSame('https://api.example.com/cron', $task->getUrl());
            $this->assertSame('POST', $task->getMethod());
            $this->assertNotNull($task->getId());

            // Verify scheduled time
            $expectedTime = $startTime->modify(sprintf('+%d seconds', $index * $intervalSeconds));
            $scheduledTime = $task->getScheduledTime();
            $this->assertNotNull($scheduledTime);
            $this->assertSame($expectedTime->getTimestamp(), $scheduledTime->getTimestamp());

            // Verify task was persisted
            $persisted = $this->repository->find($task->getId());
            $this->assertInstanceOf(HttpRequestTask::class, $persisted);
            $this->assertNotNull($persisted->getScheduledTime());
        }
    }
}
