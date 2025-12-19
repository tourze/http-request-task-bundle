<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Tester\CommandTester;
use Tourze\HttpRequestTaskBundle\Command\RetryFailedTasksCommand;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;
use Tourze\HttpRequestTaskBundle\Repository\HttpRequestTaskRepository;
use Tourze\PHPUnitSymfonyKernelTest\AbstractCommandTestCase;

/**
 * RetryFailedTasksCommand 集成测试。
 *
 * 注意：此测试使用真实服务和数据库实体。
 * retryTask() 方法会向 Symfony Messenger 分发消息，
 * 在测试环境中可能不会同步处理。
 * 我们专注于测试命令行为和输出，而非异步消息处理的副作用。
 *
 * @internal
 */
#[CoversClass(RetryFailedTasksCommand::class)]
#[RunTestsInSeparateProcesses]
final class RetryFailedTasksCommandTest extends AbstractCommandTestCase
{
    private HttpRequestTaskRepository $taskRepository;

    protected function onSetUp(): void
    {
        $this->taskRepository = self::getService(HttpRequestTaskRepository::class);

        // Clean up all existing tasks before each test to ensure isolation
        $em = self::getEntityManager();
        $em->createQuery('DELETE FROM ' . HttpRequestTask::class)->execute();
        $em->clear();
    }

    protected function getCommandTester(): CommandTester
    {
        $command = self::getService(RetryFailedTasksCommand::class);

        return new CommandTester($command);
    }

    public function testRetrySingleTaskSuccess(): void
    {
        $task = $this->createFailedTask(canRetry: true);
        $taskId = $task->getId();

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'task-id' => (string) $taskId,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Task ' . $taskId . ' has been queued for retry', $output);
        $this->assertEquals(0, $commandTester->getStatusCode(), 'Command should succeed: ' . $output);
    }

    public function testRetrySingleTaskNotFound(): void
    {
        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'task-id' => '999999',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Task with ID 999999 not found', $output);
        $this->assertEquals(1, $commandTester->getStatusCode());
    }

    public function testRetrySingleTaskNotFailed(): void
    {
        $task = $this->createTask(status: HttpRequestTask::STATUS_COMPLETED);

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'task-id' => (string) $task->getId(),
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Task ' . $task->getId() . ' is not in failed status', $output);
        $this->assertEquals(1, $commandTester->getStatusCode());
    }

    public function testRetrySingleTaskExceededMaxAttempts(): void
    {
        $task = $this->createFailedTask(canRetry: false);

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'task-id' => (string) $task->getId(),
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Task ' . $task->getId() . ' has exceeded maximum retry attempts', $output);
        $this->assertEquals(1, $commandTester->getStatusCode());
    }

    public function testRetrySingleTaskWithForce(): void
    {
        $task = $this->createFailedTask(canRetry: false);

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'task-id' => (string) $task->getId(),
            '--force' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Task ' . $task->getId() . ' has been queued for retry', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testRetrySingleTaskDryRun(): void
    {
        $task = $this->createFailedTask(canRetry: true);

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'task-id' => (string) $task->getId(),
            '--dry-run' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Dry run mode', $output);
        $this->assertStringContainsString('Would retry task ' . $task->getId(), $output);
        $this->assertEquals(0, $commandTester->getStatusCode());

        // Verify task status was NOT changed in dry-run mode
        self::getEntityManager()->clear();
        $reloadedTask = $this->taskRepository->find($task->getId());
        $this->assertNotNull($reloadedTask);
        $this->assertEquals(HttpRequestTask::STATUS_FAILED, $reloadedTask->getStatus());
    }

    public function testRetryMultipleTasksSuccess(): void
    {
        $this->createFailedTask(canRetry: true);
        $this->createFailedTask(canRetry: true);
        $this->createFailedTask(canRetry: false);

        $commandTester = $this->getCommandTester();
        $commandTester->execute([]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Found 3 failed tasks', $output);
        $this->assertStringContainsString('retried', $output);
        $this->assertStringContainsString('skipped', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testRetryMultipleTasksWithLimit(): void
    {
        // Create more tasks than the limit
        $this->createFailedTask(canRetry: true);
        $this->createFailedTask(canRetry: true);
        $this->createFailedTask(canRetry: true);

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--limit' => '2',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Found 2 failed tasks', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testRetryMultipleTasksDryRun(): void
    {
        $task1 = $this->createFailedTask(canRetry: true);
        $task2 = $this->createFailedTask(canRetry: false);

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--dry-run' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Dry run mode', $output);
        $this->assertStringContainsString('Found 2 failed tasks', $output);
        $this->assertStringContainsString('Would retry', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());

        // Verify no tasks were changed in dry-run mode
        self::getEntityManager()->clear();
        $reloadedTask1 = $this->taskRepository->find($task1->getId());
        $reloadedTask2 = $this->taskRepository->find($task2->getId());

        $this->assertNotNull($reloadedTask1);
        $this->assertEquals(HttpRequestTask::STATUS_FAILED, $reloadedTask1->getStatus());

        $this->assertNotNull($reloadedTask2);
        $this->assertEquals(HttpRequestTask::STATUS_FAILED, $reloadedTask2->getStatus());
    }

    public function testRetryNoFailedTasks(): void
    {
        // Create only non-failed tasks
        $this->createTask(status: HttpRequestTask::STATUS_COMPLETED);
        $this->createTask(status: HttpRequestTask::STATUS_PENDING);

        $commandTester = $this->getCommandTester();
        $commandTester->execute([]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('No failed tasks found', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testArgumentTaskId(): void
    {
        $task = $this->createFailedTask(canRetry: true);

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'task-id' => (string) $task->getId(),
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Task ' . $task->getId(), $output);
        $this->assertStringContainsString('queued for retry', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testOptionLimit(): void
    {
        $this->createFailedTask(canRetry: true);
        $this->createFailedTask(canRetry: true);
        $this->createFailedTask(canRetry: true);

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--limit' => '5',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Found 3 failed tasks', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testOptionForce(): void
    {
        $this->createFailedTask(canRetry: false);

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--force' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Found 1 failed tasks', $output);
        $this->assertStringContainsString('Retry complete', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testOptionDryRun(): void
    {
        $this->createFailedTask(canRetry: true);
        $this->createFailedTask(canRetry: true);

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--dry-run' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Dry run mode', $output);
        $this->assertStringContainsString('Found 2 failed tasks', $output);
        $this->assertStringContainsString('Would retry', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    private function createFailedTask(bool $canRetry): HttpRequestTask
    {
        return $this->createTask(
            status: HttpRequestTask::STATUS_FAILED,
            attempts: $canRetry ? 1 : 3,
            maxAttempts: 3
        );
    }

    private function createTask(
        string $status = HttpRequestTask::STATUS_FAILED,
        int $attempts = 1,
        int $maxAttempts = 3
    ): HttpRequestTask {
        $task = new HttpRequestTask();
        $task->setUuid(uniqid('test_', true));
        $task->setUrl('https://example.com/test-' . uniqid());
        $task->setMethod(HttpRequestTask::METHOD_GET);
        $task->setStatus($status);
        $task->setMaxAttempts($maxAttempts);
        $task->setAttempts($attempts);

        self::getEntityManager()->persist($task);
        self::getEntityManager()->flush();

        return $task;
    }
}
