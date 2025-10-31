<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Tester\CommandTester;
use Tourze\HttpRequestTaskBundle\Command\RetryFailedTasksCommand;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;
use Tourze\HttpRequestTaskBundle\Exception\TaskExecutionException;
use Tourze\HttpRequestTaskBundle\Service\HttpRequestTaskService;
use Tourze\PHPUnitSymfonyKernelTest\AbstractCommandTestCase;

/**
 * @internal
 */
#[CoversClass(RetryFailedTasksCommand::class)]
#[RunTestsInSeparateProcesses]
final class RetryFailedTasksCommandTest extends AbstractCommandTestCase
{
    private HttpRequestTaskService $taskService;

    protected function onSetUp(): void
    {
        $this->taskService = $this->createMock(HttpRequestTaskService::class);

        // Replace service in container
        self::getContainer()->set(HttpRequestTaskService::class, $this->taskService);
    }

    protected function getCommandTester(): CommandTester
    {
        $command = self::getService(RetryFailedTasksCommand::class);

        return new CommandTester($command);
    }

    public function testRetrySingleTaskSuccess(): void
    {
        $task = $this->createMockTask(1, true);

        $this->taskService->expects($this->once())
            ->method('findTaskById')
            ->with(1)
            ->willReturn($task)
        ;

        $this->taskService->expects($this->once())
            ->method('retryTask')
            ->with($task)
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'task-id' => '1',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Task 1 has been queued for retry', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testRetrySingleTaskNotFound(): void
    {
        $this->taskService->expects($this->once())
            ->method('findTaskById')
            ->with(1)
            ->willReturn(null)
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'task-id' => '1',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Task with ID 1 not found', $output);
        $this->assertEquals(1, $commandTester->getStatusCode());
    }

    public function testRetrySingleTaskNotFailed(): void
    {
        $task = $this->createMockTask(1, true, HttpRequestTask::STATUS_COMPLETED);

        $this->taskService->expects($this->once())
            ->method('findTaskById')
            ->with(1)
            ->willReturn($task)
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'task-id' => '1',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Task 1 is not in failed status', $output);
        $this->assertEquals(1, $commandTester->getStatusCode());
    }

    public function testRetrySingleTaskExceededMaxAttempts(): void
    {
        $task = $this->createMockTask(1, false);

        $this->taskService->expects($this->once())
            ->method('findTaskById')
            ->with(1)
            ->willReturn($task)
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'task-id' => '1',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Task 1 has exceeded maximum retry attempts', $output);
        $this->assertEquals(1, $commandTester->getStatusCode());
    }

    public function testRetrySingleTaskWithForce(): void
    {
        $task = $this->createMockTask(1, false);

        $this->taskService->expects($this->once())
            ->method('findTaskById')
            ->with(1)
            ->willReturn($task)
        ;

        $task->expects($this->once())
            ->method('setMaxAttempts')
            ->with(4)
        ;

        $this->taskService->expects($this->once())
            ->method('retryTask')
            ->with($task)
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'task-id' => '1',
            '--force' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Task 1 has been queued for retry', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testRetrySingleTaskDryRun(): void
    {
        $task = $this->createMockTask(1, true);

        $this->taskService->expects($this->once())
            ->method('findTaskById')
            ->with(1)
            ->willReturn($task)
        ;

        $this->taskService->expects($this->never())
            ->method('retryTask')
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'task-id' => '1',
            '--dry-run' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Dry run mode', $output);
        $this->assertStringContainsString('Would retry task 1', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testRetryMultipleTasksSuccess(): void
    {
        $task1 = $this->createMockTask(1, true);
        $task2 = $this->createMockTask(2, true);
        $task3 = $this->createMockTask(3, false);

        $this->taskService->expects($this->once())
            ->method('findFailedTasks')
            ->with(100)
            ->willReturn([$task1, $task2, $task3])
        ;

        $this->taskService->expects($this->exactly(2))
            ->method('retryTask')
            ->willReturnCallback(function ($task): void {
                if (3 === $task->getId()) {
                    throw new TaskExecutionException('Cannot retry');
                }
            })
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Found 3 failed tasks', $output);
        $this->assertStringContainsString('Retry complete: 2 retried, 1 skipped, 0 errors', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testRetryMultipleTasksWithLimit(): void
    {
        $task1 = $this->createMockTask(1, true);
        $task2 = $this->createMockTask(2, true);

        $this->taskService->expects($this->once())
            ->method('findFailedTasks')
            ->with(50)
            ->willReturn([$task1, $task2])
        ;

        $this->taskService->expects($this->exactly(2))
            ->method('retryTask')
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--limit' => '50',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Found 2 failed tasks', $output);
        $this->assertStringContainsString('Retry complete: 2 retried, 0 skipped, 0 errors', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testRetryMultipleTasksDryRun(): void
    {
        $task1 = $this->createMockTask(1, true);
        $task2 = $this->createMockTask(2, false);

        $this->taskService->expects($this->once())
            ->method('findFailedTasks')
            ->with(100)
            ->willReturn([$task1, $task2])
        ;

        $this->taskService->expects($this->never())
            ->method('retryTask')
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--dry-run' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Dry run mode', $output);
        $this->assertStringContainsString('Found 2 failed tasks', $output);
        $this->assertStringContainsString('Would retry 2 tasks', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testRetryNoFailedTasks(): void
    {
        $this->taskService->expects($this->once())
            ->method('findFailedTasks')
            ->with(100)
            ->willReturn([])
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('No failed tasks found', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testArgumentTaskId(): void
    {
        $task = $this->createMockTask(123, true);

        $this->taskService->expects($this->once())
            ->method('findTaskById')
            ->with(123)
            ->willReturn($task)
        ;

        $this->taskService->expects($this->once())
            ->method('retryTask')
            ->with($task)
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            'task-id' => '123',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Task 123', $output);
        $this->assertStringContainsString('queued for retry', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testOptionLimit(): void
    {
        $task1 = $this->createMockTask(1, true);
        $task2 = $this->createMockTask(2, true);
        $task3 = $this->createMockTask(3, true);

        $this->taskService->expects($this->once())
            ->method('findFailedTasks')
            ->with(5)
            ->willReturn([$task1, $task2, $task3])
        ;

        $this->taskService->expects($this->exactly(3))
            ->method('retryTask')
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--limit' => '5',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Found 3 failed tasks', $output);
        $this->assertStringContainsString('Retry complete: 3 retried, 0 skipped, 0 errors', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testOptionForce(): void
    {
        $task = $this->createMockTask(1, false);

        $this->taskService->expects($this->once())
            ->method('findFailedTasks')
            ->with(100)
            ->willReturn([$task])
        ;

        $this->taskService->expects($this->once())
            ->method('retryTask')
            ->with($task)
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--force' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Found 1 failed tasks', $output);
        $this->assertStringContainsString('Retry complete: 1 retried, 0 skipped, 0 errors', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testOptionDryRun(): void
    {
        $task1 = $this->createMockTask(1, true);
        $task2 = $this->createMockTask(2, true);

        $this->taskService->expects($this->once())
            ->method('findFailedTasks')
            ->with(100)
            ->willReturn([$task1, $task2])
        ;

        $this->taskService->expects($this->never())
            ->method('retryTask')
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--dry-run' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Dry run mode', $output);
        $this->assertStringContainsString('Found 2 failed tasks', $output);
        $this->assertStringContainsString('Would retry 2 tasks', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    private function createMockTask(int $id, bool $canRetry, string $status = HttpRequestTask::STATUS_FAILED): HttpRequestTask
    {
        $task = $this->createMock(HttpRequestTask::class);
        $task->method('getId')->willReturn($id);
        $task->method('getUrl')->willReturn('https://example.com/task-' . $id);
        $task->method('getStatus')->willReturn($status);
        $task->method('getMethod')->willReturn('GET');
        $task->method('canRetry')->willReturn($canRetry);
        $task->method('getAttempts')->willReturn(3);
        $task->method('getMaxAttempts')->willReturn(3);

        return $task;
    }
}
