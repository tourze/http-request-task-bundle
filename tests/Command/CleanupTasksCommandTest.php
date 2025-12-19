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
        $this->taskRepository = self::getService(HttpRequestTaskRepository::class);
        $this->logRepository = self::getService(HttpRequestLogRepository::class);

        // Clean up all existing data (including fixtures)
        $this->cleanupAllData();
    }

    private function cleanupAllData(): void
    {
        $em = self::getEntityManager();

        // Delete all logs first (due to foreign key constraints)
        $em->createQuery('DELETE FROM ' . HttpRequestLog::class)->execute();

        // Delete all tasks
        $em->createQuery('DELETE FROM ' . HttpRequestTask::class)->execute();

        $em->clear();
    }

    protected function getCommandTester(): CommandTester
    {
        $command = self::getService(CleanupTasksCommand::class);

        return new CommandTester($command);
    }

    public function testExecuteWithDryRun(): void
    {
        // Create old tasks
        $task1 = $this->createOldTask(100);
        $task2 = $this->createOldTask(95);

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--dry-run' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('DRY RUN MODE', $output);
        $this->assertStringContainsString('Found 2 tasks to delete', $output);
        $this->assertStringContainsString('Found 2 logs to delete', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());

        // Verify tasks were not deleted
        $this->assertCount(2, $this->taskRepository->findAll());
        $this->assertCount(2, $this->logRepository->findAll());

        // Verify specific tasks still exist
        $this->assertNotNull($this->taskRepository->find($task1->getId()));
        $this->assertNotNull($this->taskRepository->find($task2->getId()));
    }

    public function testExecuteWithoutDryRun(): void
    {
        // Create old completed tasks (all > 90 days to be deleted by default)
        $task1 = $this->createOldTask(100);
        $task2 = $this->createOldTask(95);
        $task3 = $this->createOldTask(92);

        $createdIds = [
            $task1->getId(),
            $task2->getId(),
            $task3->getId(),
        ];

        $commandTester = $this->getCommandTester();
        $commandTester->execute([]);

        $output = $commandTester->getDisplay();
        // Default is 90 days, so only tasks > 90 days are deleted
        $this->assertStringContainsString('Cleanup complete: 3 tasks and 3 logs deleted', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());

        // Clear entity manager cache to query fresh data from DB
        self::getEntityManager()->clear();

        // Verify tasks were deleted
        $this->assertCount(0, $this->taskRepository->findAll());
        $this->assertCount(0, $this->logRepository->findAll());

        // Verify specific tasks no longer exist
        foreach ($createdIds as $id) {
            $this->assertNull($this->taskRepository->find($id));
        }
    }

    public function testExecuteWithCustomDays(): void
    {
        // Create tasks with different ages
        $oldTask1 = $this->createOldTask(100); // Should be deleted (> 30 days)
        $oldTask2 = $this->createOldTask(50);  // Should be deleted (> 30 days)
        $recentTask = $this->createOldTask(20);  // Should NOT be deleted (< 30 days)

        $recentTaskId = $recentTask->getId();

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--days' => '30',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Cleanup complete: 2 tasks and 2 logs deleted', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());

        // Clear entity manager cache to query fresh data from DB
        self::getEntityManager()->clear();

        // Verify only old tasks were deleted, recent task remains
        $this->assertCount(1, $this->taskRepository->findAll());
        $this->assertCount(1, $this->logRepository->findAll());

        // Verify recent task still exists
        $remainingTask = $this->taskRepository->find($recentTaskId);
        $this->assertNotNull($remainingTask);
        $this->assertStringContainsString('-20-days', $remainingTask->getUrl());
    }

    public function testExecuteLogsOnly(): void
    {
        // Create old tasks with logs
        $task1 = $this->createOldTask(100);
        $task2 = $this->createOldTask(95);
        $task3 = $this->createOldTask(92);

        $taskIds = [$task1->getId(), $task2->getId(), $task3->getId()];

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--logs-only' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Cleanup complete: 0 tasks and 3 logs deleted', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());

        // Clear entity manager cache to query fresh data from DB
        self::getEntityManager()->clear();

        // Verify only logs were deleted, tasks remain
        $this->assertCount(3, $this->taskRepository->findAll());
        $this->assertCount(0, $this->logRepository->findAll());

        // Verify tasks still exist
        foreach ($taskIds as $id) {
            $this->assertNotNull($this->taskRepository->find($id));
        }
    }

    public function testExecuteTasksOnly(): void
    {
        // Create old tasks with logs
        $task1 = $this->createOldTask(100);
        $task2 = $this->createOldTask(95);
        $task3 = $this->createOldTask(92);

        $taskIds = [$task1->getId(), $task2->getId(), $task3->getId()];

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--tasks-only' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Cleanup complete: 3 tasks and 0 logs deleted', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());

        // Clear entity manager cache to query fresh data from DB
        self::getEntityManager()->clear();

        // Verify all tasks were deleted
        // Note: DQL bulk delete does not trigger Doctrine cascade,
        // so logs may remain as orphans (depending on DB foreign key constraints)
        $this->assertCount(0, $this->taskRepository->findAll());

        // Verify specific tasks no longer exist
        foreach ($taskIds as $id) {
            $this->assertNull($this->taskRepository->find($id));
        }
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
        // Create tasks with specific ages
        $oldTask = $this->createOldTask(20);
        $recentTask = $this->createOldTask(10);

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--days' => '15',
            '--dry-run' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('DRY RUN MODE', $output);
        $this->assertStringContainsString('Found 1 tasks to delete', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testOptionDryRun(): void
    {
        $task = $this->createOldTask(100);
        $taskId = $task->getId();

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--dry-run' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('DRY RUN MODE', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());

        // Verify no deletion occurred
        $this->assertNotNull($this->taskRepository->find($taskId));
    }

    public function testOptionLogsOnly(): void
    {
        // Create 5 tasks, but only 3 are old enough to be deleted (>90 days)
        $task1 = $this->createOldTask(100);  // Will be deleted
        $task2 = $this->createOldTask(95);   // Will be deleted
        $task3 = $this->createOldTask(92);   // Will be deleted
        $task4 = $this->createOldTask(88);   // Will NOT be deleted (<90 days)
        $task5 = $this->createOldTask(85);   // Will NOT be deleted (<90 days)

        $oldTaskIds = [$task1->getId(), $task2->getId(), $task3->getId()];
        $recentTaskIds = [$task4->getId(), $task5->getId()];

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--logs-only' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Cleanup complete: 0 tasks and 3 logs deleted', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());

        // Clear entity manager cache to query fresh data from DB
        self::getEntityManager()->clear();

        // Verify only old logs deleted, tasks and recent logs remain
        $this->assertCount(5, $this->taskRepository->findAll());
        $this->assertCount(2, $this->logRepository->findAll());

        // Verify all tasks still exist
        foreach (array_merge($oldTaskIds, $recentTaskIds) as $id) {
            $this->assertNotNull($this->taskRepository->find($id));
        }
    }

    public function testOptionTasksOnly(): void
    {
        $task1 = $this->createOldTask(100);
        $task2 = $this->createOldTask(95);
        $task3 = $this->createOldTask(92);

        $taskIds = [$task1->getId(), $task2->getId(), $task3->getId()];

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--tasks-only' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Cleanup complete: 3 tasks and 0 logs deleted', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());

        // Clear entity manager cache to ensure we query fresh data from DB
        self::getEntityManager()->clear();

        // Verify tasks deleted (DQL bulk delete does not trigger Doctrine cascade)
        $this->assertCount(0, $this->taskRepository->findAll());

        // Verify specific tasks no longer exist
        foreach ($taskIds as $id) {
            $this->assertNull($this->taskRepository->find($id));
        }
    }

    public function testOptionBatchSize(): void
    {
        // Create more tasks than batch size
        $task1 = $this->createOldTask(100);
        $task2 = $this->createOldTask(95);

        $taskIds = [$task1->getId(), $task2->getId()];

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--batch-size' => '1', // Process one at a time
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Cleanup complete: 2 tasks and 2 logs deleted', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());

        // Clear entity manager cache to query fresh data from DB
        self::getEntityManager()->clear();

        // Verify all tasks were deleted despite small batch size
        $this->assertCount(0, $this->taskRepository->findAll());

        foreach ($taskIds as $id) {
            $this->assertNull($this->taskRepository->find($id));
        }
    }

    public function testOnlyDeletesCompletedFailedOrCancelledTasks(): void
    {
        // Create old tasks with different statuses
        $pendingTask = $this->createOldTask(100);
        $pendingTask->setStatus(HttpRequestTask::STATUS_PENDING);
        self::getEntityManager()->flush();

        $processingTask = $this->createOldTask(100);
        $processingTask->setStatus(HttpRequestTask::STATUS_PROCESSING);
        self::getEntityManager()->flush();

        $completedTask = $this->createOldTask(100);
        $completedTask->setStatus(HttpRequestTask::STATUS_COMPLETED);
        self::getEntityManager()->flush();

        $failedTask = $this->createOldTask(100);
        $failedTask->setStatus(HttpRequestTask::STATUS_FAILED);
        self::getEntityManager()->flush();

        $cancelledTask = $this->createOldTask(100);
        $cancelledTask->setStatus(HttpRequestTask::STATUS_CANCELLED);
        self::getEntityManager()->flush();

        $commandTester = $this->getCommandTester();
        $commandTester->execute([]);

        $output = $commandTester->getDisplay();
        // Note: Task deletion only affects completed/failed/cancelled tasks (3),
        // but log deletion is based solely on createdTime, so all 5 logs are deleted
        $this->assertStringContainsString('Cleanup complete: 3 tasks and 5 logs deleted', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());

        // Clear entity manager cache to query fresh data from DB
        self::getEntityManager()->clear();

        // Verify only completed/failed/cancelled tasks were deleted (2 remain)
        $this->assertCount(2, $this->taskRepository->findAll());
        // All old logs are deleted regardless of task status
        $this->assertCount(0, $this->logRepository->findAll());

        // Verify pending and processing tasks still exist
        $this->assertNotNull($this->taskRepository->find($pendingTask->getId()));
        $this->assertNotNull($this->taskRepository->find($processingTask->getId()));

        // Verify completed/failed/cancelled tasks were deleted
        $this->assertNull($this->taskRepository->find($completedTask->getId()));
        $this->assertNull($this->taskRepository->find($failedTask->getId()));
        $this->assertNull($this->taskRepository->find($cancelledTask->getId()));
    }

    private function createOldTask(int $daysOld): HttpRequestTask
    {
        $task = new HttpRequestTask();
        $task->setUrl("https://example.com/test-{$daysOld}-days");
        $task->setMethod(HttpRequestTask::METHOD_GET);
        $task->setStatus(HttpRequestTask::STATUS_COMPLETED);

        // Set createdTime using reflection to bypass constructor
        $reflection = new \ReflectionClass($task);
        $property = $reflection->getProperty('createdTime');
        $property->setAccessible(true);
        $property->setValue($task, new \DateTimeImmutable("-{$daysOld} days"));

        self::getEntityManager()->persist($task);
        self::getEntityManager()->flush();

        // Create associated log
        $this->createLogForTask($task, 1);

        return $task;
    }

    private function createLogForTask(HttpRequestTask $task, int $attemptNumber): HttpRequestLog
    {
        $log = new HttpRequestLog();
        $log->setTask($task);
        $log->setAttemptNumber($attemptNumber);
        $log->setResult(HttpRequestLog::RESULT_SUCCESS);
        $log->setResponseCode(200);
        $log->setResponseTime(100);

        // Set createdTime to match task's createdTime
        $reflection = new \ReflectionClass($log);
        $property = $reflection->getProperty('createdTime');
        $property->setAccessible(true);
        $property->setValue($log, $task->getCreatedTime());

        self::getEntityManager()->persist($log);
        self::getEntityManager()->flush();

        return $log;
    }
}
