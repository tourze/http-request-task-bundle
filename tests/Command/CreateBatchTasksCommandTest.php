<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Tourze\HttpRequestTaskBundle\Command\CreateBatchTasksCommand;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;
use Tourze\HttpRequestTaskBundle\Repository\HttpRequestTaskRepository;
use Tourze\PHPUnitSymfonyKernelTest\AbstractCommandTestCase;

/**
 * @internal
 */
#[CoversClass(CreateBatchTasksCommand::class)]
#[RunTestsInSeparateProcesses]
final class CreateBatchTasksCommandTest extends AbstractCommandTestCase
{
    private HttpRequestTaskRepository $taskRepository;

    private string $testDataDir;

    protected function onSetUp(): void
    {
        $this->taskRepository = self::getService(HttpRequestTaskRepository::class);

        // 替换 MessageBus 为一个 no-op 实现，避免任务被实际执行
        $nullMessageBus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return new Envelope($message, $stamps);
            }
        };
        self::getContainer()->set(MessageBusInterface::class, $nullMessageBus);

        // 清空所有任务数据（包括 fixtures 加载的数据）
        $em = self::getEntityManager();
        $em->createQuery('DELETE FROM ' . HttpRequestTask::class)->execute();
        $em->clear();

        $this->testDataDir = sys_get_temp_dir() . '/http-request-task-test-' . uniqid();
        mkdir($this->testDataDir);
    }

    protected function onTearDown(): void
    {
        // 清理临时文件
        if (is_dir($this->testDataDir)) {
            $files = glob($this->testDataDir . '/*');
            if (false !== $files) {
                array_map('unlink', $files);
            }
            rmdir($this->testDataDir);
        }
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

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'urls',
            'source' => $urlsFile,
        ]);

        $output = $commandTester->getDisplay();
        self::assertStringContainsString('Created 3 tasks successfully', $output);
        self::assertEquals(0, $commandTester->getStatusCode());

        // 验证任务已创建到数据库
        $tasks = $this->taskRepository->findAll();
        self::assertCount(3, $tasks);
        self::assertEquals('https://api.example.com/1', $tasks[0]->getUrl());
        self::assertEquals('https://api.example.com/2', $tasks[1]->getUrl());
        self::assertEquals('https://api.example.com/3', $tasks[2]->getUrl());
        self::assertEquals(HttpRequestTask::STATUS_PENDING, $tasks[0]->getStatus());
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

        // 注意：必须指定 --method POST，否则 commonOptions 的默认 GET 会覆盖 endpoint 中的 method
        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'api',
            'source' => $apiFile,
            '--method' => 'POST',
        ]);

        $output = $commandTester->getDisplay();
        self::assertStringContainsString('Created 2 tasks successfully', $output);
        self::assertEquals(0, $commandTester->getStatusCode());

        // 验证任务已创建
        $tasks = $this->taskRepository->findAll();
        self::assertCount(2, $tasks);
        self::assertEquals('https://api.example.com/users', $tasks[0]->getUrl());
        self::assertEquals('POST', $tasks[0]->getMethod());
        self::assertEquals('{"name":"John"}', $tasks[0]->getBody());
        self::assertEquals('application/json', $tasks[0]->getContentType());
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

        // 注意：必须指定 --method POST，否则 commonOptions 的默认 GET 会覆盖 webhook 的 POST
        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'webhook',
            'source' => $webhookFile,
            '--method' => 'POST',
        ]);

        $output = $commandTester->getDisplay();
        self::assertStringContainsString('Created 2 tasks successfully', $output);
        self::assertEquals(0, $commandTester->getStatusCode());

        // 验证任务已创建
        $tasks = $this->taskRepository->findAll();
        self::assertCount(2, $tasks);
        self::assertEquals('https://webhook.example.com/events', $tasks[0]->getUrl());
        self::assertEquals('POST', $tasks[0]->getMethod());
        self::assertArrayHasKey('X-Event-Type', $tasks[0]->getHeaders());
        self::assertEquals('user.created', $tasks[0]->getHeaders()['X-Event-Type']);
    }

    public function testCreateScheduledTasks(): void
    {
        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'scheduled',
            'source' => 'https://api.example.com/cron',
            '--count' => '10',
            '--interval' => '60',
        ]);

        $output = $commandTester->getDisplay();
        self::assertStringContainsString('Created 10 tasks successfully', $output);
        self::assertEquals(0, $commandTester->getStatusCode());

        // 验证任务已创建
        $tasks = $this->taskRepository->findAll();
        self::assertCount(10, $tasks);
        self::assertEquals('https://api.example.com/cron', $tasks[0]->getUrl());

        // 验证定时任务的时间间隔
        $firstTime = $tasks[0]->getScheduledTime();
        $secondTime = $tasks[1]->getScheduledTime();
        self::assertInstanceOf(\DateTimeImmutable::class, $firstTime);
        self::assertInstanceOf(\DateTimeImmutable::class, $secondTime);
        self::assertEquals(60, $secondTime->getTimestamp() - $firstTime->getTimestamp());
    }

    public function testCreateWithCustomOptions(): void
    {
        $urlsFile = $this->testDataDir . '/urls.json';
        file_put_contents($urlsFile, json_encode([
            'urls' => ['https://api.example.com/test'],
        ]));

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
        self::assertStringContainsString('Created 1 tasks successfully', $output);
        self::assertEquals(0, $commandTester->getStatusCode());

        // 验证自定义选项
        $tasks = $this->taskRepository->findAll();
        self::assertCount(1, $tasks);
        self::assertEquals('POST', $tasks[0]->getMethod());
        self::assertEquals(HttpRequestTask::PRIORITY_HIGH, $tasks[0]->getPriority());
        self::assertEquals(60, $tasks[0]->getTimeout());
        self::assertEquals(5, $tasks[0]->getMaxAttempts());
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

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'urls',
            'source' => $urlsFile,
            '--dry-run' => true,
        ]);

        $output = $commandTester->getDisplay();
        self::assertStringContainsString('DRY RUN MODE', $output);
        self::assertStringContainsString('Tasks to be created', $output);
        self::assertStringContainsString('Would create 2 tasks', $output);
        self::assertEquals(0, $commandTester->getStatusCode());

        // 验证没有任务被创建（dry-run 模式下不应该创建任务）
        $tasks = $this->taskRepository->findAll();
        self::assertCount(0, $tasks);
    }

    public function testInvalidBatchType(): void
    {
        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'invalid',
            'source' => 'dummy',
        ]);

        $output = $commandTester->getDisplay();
        self::assertStringContainsString('Unknown batch type: invalid', $output);
        self::assertEquals(1, $commandTester->getStatusCode());
    }

    public function testFileNotFound(): void
    {
        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'urls',
            'source' => '/non/existent/file.json',
        ]);

        $output = $commandTester->getDisplay();
        self::assertStringContainsString('File not found', $output);
        self::assertEquals(1, $commandTester->getStatusCode());
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
        self::assertStringContainsString('Invalid JSON format', $output);
        self::assertEquals(1, $commandTester->getStatusCode());
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

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'urls',
            'source' => $urlsFile,
        ]);

        $output = $commandTester->getDisplay();
        self::assertStringContainsString('Created 2 tasks successfully', $output);
        self::assertEquals(0, $commandTester->getStatusCode());

        // 验证任务已创建
        $tasks = $this->taskRepository->findAll();
        self::assertCount(2, $tasks);
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

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'urls',
            'source' => $urlsFile,
        ]);

        $output = $commandTester->getDisplay();
        self::assertStringContainsString('Created 2 tasks successfully', $output);
        self::assertEquals(0, $commandTester->getStatusCode());

        // 验证任务已创建
        $tasks = $this->taskRepository->findAll();
        self::assertCount(2, $tasks);
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

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'urls',
            'source' => $urlsFile,
            '--method' => 'POST',
        ]);

        $output = $commandTester->getDisplay();
        self::assertStringContainsString('Created 2 tasks successfully', $output);
        self::assertEquals(0, $commandTester->getStatusCode());

        // 验证 method 选项
        $tasks = $this->taskRepository->findAll();
        self::assertCount(2, $tasks);
        self::assertEquals('POST', $tasks[0]->getMethod());
        self::assertEquals('POST', $tasks[1]->getMethod());
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

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'urls',
            'source' => $urlsFile,
            '--priority' => 'high',
        ]);

        $output = $commandTester->getDisplay();
        self::assertStringContainsString('Created 2 tasks successfully', $output);
        self::assertEquals(0, $commandTester->getStatusCode());

        // 验证 priority 选项
        $tasks = $this->taskRepository->findAll();
        self::assertCount(2, $tasks);
        self::assertEquals(HttpRequestTask::PRIORITY_HIGH, $tasks[0]->getPriority());
        self::assertEquals(HttpRequestTask::PRIORITY_HIGH, $tasks[1]->getPriority());
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

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'urls',
            'source' => $urlsFile,
            '--timeout' => '120',
        ]);

        $output = $commandTester->getDisplay();
        self::assertStringContainsString('Created 2 tasks successfully', $output);
        self::assertEquals(0, $commandTester->getStatusCode());

        // 验证 timeout 选项
        $tasks = $this->taskRepository->findAll();
        self::assertCount(2, $tasks);
        self::assertEquals(120, $tasks[0]->getTimeout());
        self::assertEquals(120, $tasks[1]->getTimeout());
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

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'urls',
            'source' => $urlsFile,
            '--max-attempts' => '5',
        ]);

        $output = $commandTester->getDisplay();
        self::assertStringContainsString('Created 2 tasks successfully', $output);
        self::assertEquals(0, $commandTester->getStatusCode());

        // 验证 max-attempts 选项
        $tasks = $this->taskRepository->findAll();
        self::assertCount(2, $tasks);
        self::assertEquals(5, $tasks[0]->getMaxAttempts());
        self::assertEquals(5, $tasks[1]->getMaxAttempts());
    }

    public function testOptionScheduledTime(): void
    {
        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'scheduled',
            'source' => 'https://api.example.com/scheduled',
            '--scheduled-time' => '2024-12-25 10:30:00',
            '--count' => '3',
            '--interval' => '30',
        ]);

        $output = $commandTester->getDisplay();
        self::assertStringContainsString('Created 3 tasks successfully', $output);
        self::assertEquals(0, $commandTester->getStatusCode());

        // 验证 scheduled-time 选项
        $tasks = $this->taskRepository->findAll();
        self::assertCount(3, $tasks);
        $firstTime = $tasks[0]->getScheduledTime();
        self::assertInstanceOf(\DateTimeImmutable::class, $firstTime);
        self::assertEquals('2024-12-25 10:30:00', $firstTime->format('Y-m-d H:i:s'));
    }

    public function testOptionInterval(): void
    {
        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'scheduled',
            'source' => 'https://api.example.com/interval-test',
            '--interval' => '300',
            '--count' => '2',
        ]);

        $output = $commandTester->getDisplay();
        self::assertStringContainsString('Created 2 tasks successfully', $output);
        self::assertEquals(0, $commandTester->getStatusCode());

        // 验证 interval 选项
        $tasks = $this->taskRepository->findAll();
        self::assertCount(2, $tasks);
        $firstTime = $tasks[0]->getScheduledTime();
        $secondTime = $tasks[1]->getScheduledTime();
        self::assertInstanceOf(\DateTimeImmutable::class, $firstTime);
        self::assertInstanceOf(\DateTimeImmutable::class, $secondTime);
        self::assertEquals(300, $secondTime->getTimestamp() - $firstTime->getTimestamp());
    }

    public function testOptionCount(): void
    {
        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'scheduled',
            'source' => 'https://api.example.com/count-test',
            '--count' => '5',
            '--interval' => '60',
        ]);

        $output = $commandTester->getDisplay();
        self::assertStringContainsString('Created 5 tasks successfully', $output);
        self::assertEquals(0, $commandTester->getStatusCode());

        // 验证 count 选项
        $tasks = $this->taskRepository->findAll();
        self::assertCount(5, $tasks);
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

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'type' => 'urls',
            'source' => $urlsFile,
            '--dry-run' => true,
        ]);

        $output = $commandTester->getDisplay();
        self::assertStringContainsString('DRY RUN MODE', $output);
        self::assertStringContainsString('Tasks to be created', $output);
        self::assertStringContainsString('Would create 3 tasks', $output);
        self::assertEquals(0, $commandTester->getStatusCode());

        // 验证没有任务被创建
        $tasks = $this->taskRepository->findAll();
        self::assertCount(0, $tasks);
    }
}
