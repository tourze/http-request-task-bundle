<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Tester\CommandTester;
use Tourze\HttpRequestTaskBundle\Command\CreateBatchTasksCommand;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;
use Tourze\HttpRequestTaskBundle\Service\BatchTaskService;
use Tourze\PHPUnitSymfonyKernelTest\AbstractCommandTestCase;

/**
 * @internal
 */
#[CoversClass(CreateBatchTasksCommand::class)]
#[RunTestsInSeparateProcesses]
final class CreateBatchTasksCommandTest extends AbstractCommandTestCase
{
    private BatchTaskService $batchTaskService;

    private string $testDataDir;

    protected function onSetUp(): void
    {
        $this->batchTaskService = $this->createMock(BatchTaskService::class);

        // Replace service in container
        self::getContainer()->set(BatchTaskService::class, $this->batchTaskService);

        $this->testDataDir = sys_get_temp_dir() . '/http-request-task-test-' . uniqid();
        mkdir($this->testDataDir);
    }

    protected function getCommandTester(): CommandTester
    {
        $command = self::getService(CreateBatchTasksCommand::class);

        return new CommandTester($command);
    }

    public function testCreateFromUrlsFile(): void
    {
        $urlsFile = $this->testDataDir . '/urls.json';
        file_put_contents($urlsFile, json_encode([
            'urls' => [
                'https://api.example.com/1',
                'https://api.example.com/2',
                'https://api.example.com/3',
            ],
        ]));

        $tasks = [
            $this->createMockTask(1),
            $this->createMockTask(2),
            $this->createMockTask(3),
        ];

        $this->batchTaskService->expects($this->once())
            ->method('createBatch')
            ->willReturn($tasks)
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'urls',
            'source' => $urlsFile,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Created 3 tasks successfully', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testCreateApiCallsFile(): void
    {
        $apiFile = $this->testDataDir . '/api.json';
        file_put_contents($apiFile, json_encode([
            'endpoints' => [
                [
                    'url' => 'https://api.example.com/users',
                    'method' => 'POST',
                    'data' => ['name' => 'John'],
                ],
                [
                    'url' => 'https://api.example.com/orders',
                    'data' => ['product' => 'Widget'],
                ],
            ],
        ]));

        $tasks = [
            $this->createMockTask(1),
            $this->createMockTask(2),
        ];

        $this->batchTaskService->expects($this->once())
            ->method('createBatch')
            ->willReturn($tasks)
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'api',
            'source' => $apiFile,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Created 2 tasks successfully', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testCreateWebhookEventsFile(): void
    {
        $webhookFile = $this->testDataDir . '/webhook.json';
        file_put_contents($webhookFile, json_encode([
            'webhook_url' => 'https://webhook.example.com/events',
            'events' => [
                ['type' => 'user.created', 'id' => '123'],
                ['type' => 'order.completed', 'id' => '456'],
            ],
        ]));

        $tasks = [
            $this->createMockTask(1),
            $this->createMockTask(2),
        ];

        $this->batchTaskService->expects($this->once())
            ->method('createBatch')
            ->willReturn($tasks)
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'webhook',
            'source' => $webhookFile,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Created 2 tasks successfully', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testCreateScheduledTasks(): void
    {
        $tasks = [];
        for ($i = 1; $i <= 10; ++$i) {
            $tasks[] = $this->createMockTask($i);
        }

        $this->batchTaskService->expects($this->once())
            ->method('createBatch')
            ->willReturn($tasks)
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'scheduled',
            'source' => 'https://api.example.com/cron',
            '--count' => '10',
            '--interval' => '60',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Created 10 tasks successfully', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testCreateWithCustomOptions(): void
    {
        $urlsFile = $this->testDataDir . '/urls.json';
        file_put_contents($urlsFile, json_encode([
            'urls' => ['https://api.example.com/test'],
        ]));

        $task = $this->createMockTask(1);

        $this->batchTaskService->expects($this->once())
            ->method('createBatch')
            ->willReturn([$task])
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'urls',
            'source' => $urlsFile,
            '--method' => 'POST',
            '--priority' => 'high',
            '--timeout' => '60',
            '--max-attempts' => '5',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Created 1 tasks successfully', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testCreateWithDryRun(): void
    {
        $urlsFile = $this->testDataDir . '/urls.json';
        file_put_contents($urlsFile, json_encode([
            'urls' => [
                'https://api.example.com/1',
                'https://api.example.com/2',
            ],
        ]));

        $this->batchTaskService->expects($this->never())
            ->method('createBatch')
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'urls',
            'source' => $urlsFile,
            '--dry-run' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('DRY RUN MODE', $output);
        $this->assertStringContainsString('Tasks to be created', $output);
        $this->assertStringContainsString('Would create 2 tasks', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testInvalidBatchType(): void
    {
        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'invalid',
            'source' => 'dummy',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Unknown batch type: invalid', $output);
        $this->assertEquals(1, $commandTester->getStatusCode());
    }

    public function testFileNotFound(): void
    {
        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'urls',
            'source' => '/non/existent/file.json',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('File not found', $output);
        $this->assertEquals(1, $commandTester->getStatusCode());
    }

    public function testInvalidJsonFormat(): void
    {
        $invalidFile = $this->testDataDir . '/invalid.json';
        file_put_contents($invalidFile, 'not valid json');

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'urls',
            'source' => $invalidFile,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Invalid JSON format', $output);
        $this->assertEquals(1, $commandTester->getStatusCode());
    }

    public function testArgumentType(): void
    {
        $urlsFile = $this->testDataDir . '/test-urls.json';
        file_put_contents($urlsFile, json_encode([
            'urls' => [
                'https://example.com/1',
                'https://example.com/2',
            ],
        ]));

        $tasks = [
            $this->createMockTask(1),
            $this->createMockTask(2),
        ];

        $this->batchTaskService->expects($this->once())
            ->method('createBatch')
            ->willReturn($tasks)
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'urls',
            'source' => $urlsFile,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Created 2 tasks successfully', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testArgumentSource(): void
    {
        $urlsFile = $this->testDataDir . '/source-test.json';
        file_put_contents($urlsFile, json_encode([
            'urls' => [
                'https://example.com/source1',
                'https://example.com/source2',
            ],
        ]));

        $tasks = [
            $this->createMockTask(1),
            $this->createMockTask(2),
        ];

        $this->batchTaskService->expects($this->once())
            ->method('createBatch')
            ->willReturn($tasks)
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'urls',
            'source' => $urlsFile,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Created 2 tasks successfully', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testOptionMethod(): void
    {
        $urlsFile = $this->testDataDir . '/method-test.json';
        file_put_contents($urlsFile, json_encode([
            'urls' => [
                'https://example.com/post1',
                'https://example.com/post2',
            ],
        ]));

        $tasks = [
            $this->createMockTask(1),
            $this->createMockTask(2),
        ];

        $this->batchTaskService->expects($this->once())
            ->method('createBatch')
            ->willReturn($tasks)
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'urls',
            'source' => $urlsFile,
            '--method' => 'POST',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Created 2 tasks successfully', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testOptionPriority(): void
    {
        $urlsFile = $this->testDataDir . '/priority-test.json';
        file_put_contents($urlsFile, json_encode([
            'urls' => [
                'https://example.com/high1',
                'https://example.com/high2',
            ],
        ]));

        $tasks = [
            $this->createMockTask(1),
            $this->createMockTask(2),
        ];

        $this->batchTaskService->expects($this->once())
            ->method('createBatch')
            ->willReturn($tasks)
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'urls',
            'source' => $urlsFile,
            '--priority' => 'high',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Created 2 tasks successfully', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testOptionTimeout(): void
    {
        $urlsFile = $this->testDataDir . '/timeout-test.json';
        file_put_contents($urlsFile, json_encode([
            'urls' => [
                'https://example.com/timeout1',
                'https://example.com/timeout2',
            ],
        ]));

        $tasks = [
            $this->createMockTask(1),
            $this->createMockTask(2),
        ];

        $this->batchTaskService->expects($this->once())
            ->method('createBatch')
            ->willReturn($tasks)
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'urls',
            'source' => $urlsFile,
            '--timeout' => '120',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Created 2 tasks successfully', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testOptionMaxAttempts(): void
    {
        $urlsFile = $this->testDataDir . '/max-attempts-test.json';
        file_put_contents($urlsFile, json_encode([
            'urls' => [
                'https://example.com/attempts1',
                'https://example.com/attempts2',
            ],
        ]));

        $tasks = [
            $this->createMockTask(1),
            $this->createMockTask(2),
        ];

        $this->batchTaskService->expects($this->once())
            ->method('createBatch')
            ->willReturn($tasks)
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'urls',
            'source' => $urlsFile,
            '--max-attempts' => '5',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Created 2 tasks successfully', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testOptionScheduledTime(): void
    {
        $tasks = [
            $this->createMockTask(1),
            $this->createMockTask(2),
            $this->createMockTask(3),
        ];

        $this->batchTaskService->expects($this->once())
            ->method('createBatch')
            ->willReturn($tasks)
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'scheduled',
            'source' => 'https://api.example.com/scheduled',
            '--scheduled-time' => '2024-12-25 10:30:00',
            '--count' => '3',
            '--interval' => '30',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Created 3 tasks successfully', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testOptionInterval(): void
    {
        $tasks = [
            $this->createMockTask(1),
            $this->createMockTask(2),
        ];

        $this->batchTaskService->expects($this->once())
            ->method('createBatch')
            ->willReturn($tasks)
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'scheduled',
            'source' => 'https://api.example.com/interval-test',
            '--interval' => '300',
            '--count' => '2',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Created 2 tasks successfully', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testOptionCount(): void
    {
        $tasks = [];
        for ($i = 1; $i <= 5; ++$i) {
            $tasks[] = $this->createMockTask($i);
        }

        $this->batchTaskService->expects($this->once())
            ->method('createBatch')
            ->willReturn($tasks)
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'scheduled',
            'source' => 'https://api.example.com/count-test',
            '--count' => '5',
            '--interval' => '60',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Created 5 tasks successfully', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testOptionDryRun(): void
    {
        $urlsFile = $this->testDataDir . '/dry-run-test.json';
        file_put_contents($urlsFile, json_encode([
            'urls' => [
                'https://example.com/dry1',
                'https://example.com/dry2',
                'https://example.com/dry3',
            ],
        ]));

        $this->batchTaskService->expects($this->never())
            ->method('createBatch')
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'urls',
            'source' => $urlsFile,
            '--dry-run' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('DRY RUN MODE', $output);
        $this->assertStringContainsString('Tasks to be created', $output);
        $this->assertStringContainsString('Would create 3 tasks', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    private function createMockTask(int $id): HttpRequestTask
    {
        $task = $this->createMock(HttpRequestTask::class);
        $task->method('getId')->willReturn($id);
        $task->method('getUuid')->willReturn('uuid-' . $id);
        $task->method('getUrl')->willReturn('https://api.example.com/task-' . $id);
        $task->method('getStatus')->willReturn(HttpRequestTask::STATUS_PENDING);

        return $task;
    }
}
