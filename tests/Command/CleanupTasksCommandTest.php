<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Tester\CommandTester;
use Tourze\HttpRequestTaskBundle\Command\CleanupTasksCommand;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestLog;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;
use Tourze\HttpRequestTaskBundle\Repository\HttpRequestLogRepository;
use Tourze\HttpRequestTaskBundle\Repository\HttpRequestTaskRepository;
use Tourze\PHPUnitSymfonyKernelTest\AbstractCommandTestCase;

/**
 * @internal
 */
#[CoversClass(CleanupTasksCommand::class)]
#[RunTestsInSeparateProcesses]
final class CleanupTasksCommandTest extends AbstractCommandTestCase
{
    private HttpRequestTaskRepository $taskRepository;

    private HttpRequestLogRepository $logRepository;

    protected function onSetUp(): void
    {
        $this->taskRepository = $this->createMock(HttpRequestTaskRepository::class);
        $this->logRepository = $this->createMock(HttpRequestLogRepository::class);

        // Replace services in container
        self::getContainer()->set(HttpRequestTaskRepository::class, $this->taskRepository);
        self::getContainer()->set(HttpRequestLogRepository::class, $this->logRepository);
    }

    protected function getCommandTester(): CommandTester
    {
        $command = self::getService(CleanupTasksCommand::class);

        return new CommandTester($command);
    }

    public function testExecuteWithDryRun(): void
    {
        $oldDate = new \DateTimeImmutable('-90 days');

        $tasks = [
            $this->createMockTask(1, 'https://example.com/1'),
            $this->createMockTask(2, 'https://example.com/2'),
        ];

        $logs = [
            $this->createMockLog(1),
            $this->createMockLog(2),
        ];

        $this->taskRepository->expects($this->once())
            ->method('findExpiredTasks')
            ->with(self::isInstanceOf(\DateTimeImmutable::class), 1000)
            ->willReturn($tasks)
        ;

        $this->logRepository->expects($this->once())
            ->method('findExpiredLogs')
            ->with(self::isInstanceOf(\DateTimeImmutable::class), 1000)
            ->willReturn($logs)
        ;

        $this->taskRepository->expects($this->never())
            ->method('deleteOldTasks')
        ;

        $this->logRepository->expects($this->never())
            ->method('deleteOldLogs')
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--dry-run' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('DRY RUN MODE', $output);
        $this->assertStringContainsString('Found 2 tasks to delete', $output);
        $this->assertStringContainsString('Found 2 logs to delete', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testExecuteWithoutDryRun(): void
    {
        $this->taskRepository->expects($this->exactly(2))
            ->method('deleteOldTasks')
            ->willReturnOnConsecutiveCalls(5, 0)
        ;

        $this->logRepository->expects($this->exactly(2))
            ->method('deleteOldLogs')
            ->willReturnOnConsecutiveCalls(10, 0)
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Cleanup complete: 5 tasks and 10 logs deleted', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testExecuteWithCustomDays(): void
    {
        $oldDate = new \DateTimeImmutable('-30 days');

        $this->taskRepository->expects($this->exactly(2))
            ->method('deleteOldTasks')
            ->with(self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturnOnConsecutiveCalls(3, 0)
        ;

        $this->logRepository->expects($this->exactly(2))
            ->method('deleteOldLogs')
            ->with(self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturnOnConsecutiveCalls(7, 0)
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--days' => '30',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Cleanup complete: 3 tasks and 7 logs deleted', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testExecuteLogsOnly(): void
    {
        $this->taskRepository->expects($this->never())
            ->method('deleteOldTasks')
        ;

        $this->logRepository->expects($this->exactly(2))
            ->method('deleteOldLogs')
            ->willReturnOnConsecutiveCalls(15, 0)
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--logs-only' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Cleanup complete: 0 tasks and 15 logs deleted', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testExecuteTasksOnly(): void
    {
        $this->taskRepository->expects($this->exactly(2))
            ->method('deleteOldTasks')
            ->willReturnOnConsecutiveCalls(8, 0)
        ;

        $this->logRepository->expects($this->never())
            ->method('deleteOldLogs')
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--tasks-only' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Cleanup complete: 8 tasks and 0 logs deleted', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testExecuteWithConflictingOptions(): void
    {
        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--logs-only' => true,
            '--tasks-only' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Cannot use both --logs-only and --tasks-only options', $output);
        $this->assertEquals(1, $commandTester->getStatusCode());
    }

    public function testOptionDays(): void
    {
        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--days' => '15',
            '--dry-run' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('DRY RUN MODE', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testOptionDryRun(): void
    {
        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--dry-run' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('DRY RUN MODE', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testOptionLogsOnly(): void
    {
        $this->logRepository->expects($this->exactly(2))
            ->method('deleteOldLogs')
            ->willReturnOnConsecutiveCalls(5, 0)
        ;

        $this->taskRepository->expects($this->never())
            ->method('deleteOldTasks')
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--logs-only' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Cleanup complete: 0 tasks and 5 logs deleted', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testOptionTasksOnly(): void
    {
        $this->taskRepository->expects($this->exactly(2))
            ->method('deleteOldTasks')
            ->willReturnOnConsecutiveCalls(3, 0)
        ;

        $this->logRepository->expects($this->never())
            ->method('deleteOldLogs')
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--tasks-only' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Cleanup complete: 3 tasks and 0 logs deleted', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testOptionBatchSize(): void
    {
        $this->taskRepository->expects($this->exactly(2))
            ->method('deleteOldTasks')
            ->willReturnOnConsecutiveCalls(2, 0)
        ;

        $this->logRepository->expects($this->exactly(2))
            ->method('deleteOldLogs')
            ->willReturnOnConsecutiveCalls(2, 0)
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--batch-size' => '500',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Cleanup complete: 2 tasks and 2 logs deleted', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    private function createMockTask(int $id, string $url): HttpRequestTask
    {
        $task = $this->createMock(HttpRequestTask::class);
        $task->method('getId')->willReturn($id);
        $task->method('getUuid')->willReturn('uuid-' . $id);
        $task->method('getUrl')->willReturn($url);
        $task->method('getStatus')->willReturn(HttpRequestTask::STATUS_COMPLETED);
        $task->method('getCreatedTime')->willReturn(new \DateTimeImmutable('-100 days'));

        return $task;
    }

    private function createMockLog(int $id): HttpRequestLog
    {
        $task = $this->createMockTask($id, 'https://example.com/task-' . $id);

        $log = $this->createMock(HttpRequestLog::class);
        $log->method('getId')->willReturn($id);
        $log->method('getTask')->willReturn($task);
        $log->method('getResult')->willReturn(HttpRequestLog::RESULT_SUCCESS);
        $log->method('getResponseCode')->willReturn(200);
        $log->method('getCreatedTime')->willReturn(new \DateTimeImmutable('-100 days'));

        return $log;
    }
}
