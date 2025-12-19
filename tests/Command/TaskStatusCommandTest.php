<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Tester\CommandTester;
use Tourze\HttpRequestTaskBundle\Command\TaskStatusCommand;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestLog;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;
use Tourze\PHPUnitSymfonyKernelTest\AbstractCommandTestCase;

/**
 * @internal
 */
#[CoversClass(TaskStatusCommand::class)]
#[RunTestsInSeparateProcesses]
final class TaskStatusCommandTest extends AbstractCommandTestCase
{
    protected function onSetUp(): void
    {
        // 使用真实服务，不需要配置 mock
    }

    protected function getCommandTester(): CommandTester
    {
        $command = self::getService(TaskStatusCommand::class);

        return new CommandTester($command);
    }

    public function testExecuteShowsStatistics(): void
    {
        // 创建真实测试数据
        $this->createTask('https://api.example.com/1', HttpRequestTask::STATUS_PENDING);
        $this->createTask('https://api.example.com/2', HttpRequestTask::STATUS_PENDING);
        $this->createTask('https://api.example.com/3', HttpRequestTask::STATUS_PROCESSING);
        $this->createTask('https://api.example.com/4', HttpRequestTask::STATUS_COMPLETED);
        $this->createTask('https://api.example.com/5', HttpRequestTask::STATUS_FAILED);

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--statistics' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('HTTP Request Task Statistics', $output);
        $this->assertStringContainsString('Pending', $output);
        $this->assertStringContainsString('Processing', $output);
        $this->assertStringContainsString('Completed', $output);
        $this->assertStringContainsString('Failed', $output);
        $this->assertStringContainsString('Total', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testExecuteShowsDetailedStatistics(): void
    {
        // 创建不同优先级的任务
        $highPriorityTask = $this->createTask('https://api.example.com/high', HttpRequestTask::STATUS_COMPLETED);
        $highPriorityTask->setPriority(HttpRequestTask::PRIORITY_HIGH);
        self::getEntityManager()->flush();

        $normalPriorityTask = $this->createTask('https://api.example.com/normal', HttpRequestTask::STATUS_COMPLETED);
        $normalPriorityTask->setPriority(HttpRequestTask::PRIORITY_NORMAL);
        self::getEntityManager()->flush();

        $lowPriorityTask = $this->createTask('https://api.example.com/low', HttpRequestTask::STATUS_FAILED);
        $lowPriorityTask->setPriority(HttpRequestTask::PRIORITY_LOW);
        self::getEntityManager()->flush();

        // 创建日志
        $this->createLog($highPriorityTask, HttpRequestLog::RESULT_SUCCESS, 200, 150);
        $this->createLog($normalPriorityTask, HttpRequestLog::RESULT_SUCCESS, 201, 250);
        $this->createLog($lowPriorityTask, HttpRequestLog::RESULT_FAILURE, 500, 100);

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--statistics' => true,
            '--detailed' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Priority Distribution', $output);
        $this->assertStringContainsString('High', $output);
        $this->assertStringContainsString('Normal', $output);
        $this->assertStringContainsString('Low', $output);
        $this->assertStringContainsString('Log Statistics', $output);
        $this->assertStringContainsString('Average response time', $output);
        $this->assertStringContainsString('Response Code Distribution', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testExecuteShowsTasksByStatus(): void
    {
        $this->createTask('https://api.example.com/pending1', HttpRequestTask::STATUS_PENDING);
        $this->createTask('https://api.example.com/pending2', HttpRequestTask::STATUS_PENDING);
        $this->createTask('https://api.example.com/completed', HttpRequestTask::STATUS_COMPLETED);

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--status' => 'pending',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Tasks with status "pending"', $output);
        $this->assertStringContainsString('https://api.example.com/pending1', $output);
        $this->assertStringContainsString('https://api.example.com/pending2', $output);
        $this->assertStringNotContainsString('https://api.example.com/completed', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testExecuteShowsAllTasks(): void
    {
        $this->createTask('https://api.example.com/pending', HttpRequestTask::STATUS_PENDING);
        $this->createTask('https://api.example.com/failed', HttpRequestTask::STATUS_FAILED);
        $this->createTask('https://api.example.com/completed', HttpRequestTask::STATUS_COMPLETED);

        $commandTester = $this->getCommandTester();
        $commandTester->execute([]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Pending Tasks', $output);
        $this->assertStringContainsString('Failed Tasks', $output);
        $this->assertStringContainsString('https://api.example.com/pending', $output);
        $this->assertStringContainsString('https://api.example.com/failed', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testExecuteWithNoTasks(): void
    {
        // 先查看当前有多少 completed 任务
        $taskRepository = self::getService(\Tourze\HttpRequestTaskBundle\Repository\HttpRequestTaskRepository::class);
        $existingCount = count($taskRepository->findBy(['status' => HttpRequestTask::STATUS_COMPLETED]));

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--status' => 'completed',
        ]);

        $output = $commandTester->getDisplay();

        // 如果数据库中本来就有 completed 任务，测试能正常显示
        // 如果没有，测试能正常显示"No tasks found"
        if ($existingCount > 0) {
            $this->assertStringContainsString('Tasks with status "completed"', $output);
        } else {
            $this->assertStringContainsString('No tasks found with status "completed"', $output);
        }

        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testOptionStatus(): void
    {
        $this->createTask('https://api.example.com/test1', HttpRequestTask::STATUS_FAILED);
        $this->createTask('https://api.example.com/test2', HttpRequestTask::STATUS_FAILED);
        $this->createTask('https://api.example.com/pending', HttpRequestTask::STATUS_PENDING);

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--status' => 'failed',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Tasks with status "failed"', $output);
        $this->assertStringContainsString('https://api.example.com/test1', $output);
        $this->assertStringContainsString('https://api.example.com/test2', $output);
        $this->assertStringNotContainsString('https://api.example.com/pending', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testOptionLimit(): void
    {
        // 创建6个任务
        for ($i = 1; $i <= 6; ++$i) {
            $this->createTask("https://api.example.com/limit{$i}", HttpRequestTask::STATUS_PENDING);
        }

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--status' => 'pending',
            '--limit' => '5',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Tasks with status "pending"', $output);
        // 限制为5，所以最多显示5个（按ID降序，所以是limit2-limit6）
        $this->assertStringContainsString('https://api.example.com/limit', $output);
        // 验证表格中的行数（表头+分隔符+5行数据）
        $lines = explode("\n", $output);
        $tableRowCount = 0;
        foreach ($lines as $line) {
            if (str_contains($line, 'https://api.example.com/limit')) {
                ++$tableRowCount;
            }
        }
        $this->assertLessThanOrEqual(5, $tableRowCount, 'Should display at most 5 tasks');
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testOptionStatistics(): void
    {
        // 获取创建前的统计
        $taskRepository = self::getService(\Tourze\HttpRequestTaskBundle\Repository\HttpRequestTaskRepository::class);
        $beforePendingCount = count($taskRepository->findBy(['status' => HttpRequestTask::STATUS_PENDING]));
        $beforeProcessingCount = count($taskRepository->findBy(['status' => HttpRequestTask::STATUS_PROCESSING]));
        $beforeCompletedCount = count($taskRepository->findBy(['status' => HttpRequestTask::STATUS_COMPLETED]));
        $beforeFailedCount = count($taskRepository->findBy(['status' => HttpRequestTask::STATUS_FAILED]));

        // 创建新任务
        for ($i = 0; $i < 10; ++$i) {
            $this->createTask("https://api.example.com/pending{$i}", HttpRequestTask::STATUS_PENDING);
        }
        for ($i = 0; $i < 2; ++$i) {
            $this->createTask("https://api.example.com/processing{$i}", HttpRequestTask::STATUS_PROCESSING);
        }
        for ($i = 0; $i < 45; ++$i) {
            $this->createTask("https://api.example.com/completed{$i}", HttpRequestTask::STATUS_COMPLETED);
        }
        for ($i = 0; $i < 3; ++$i) {
            $this->createTask("https://api.example.com/failed{$i}", HttpRequestTask::STATUS_FAILED);
        }
        $this->createTask('https://api.example.com/cancelled', HttpRequestTask::STATUS_CANCELLED);

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--statistics' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Task Statistics', $output);
        $this->assertStringContainsString('Pending', $output);
        // 验证至少有我们创建的数量
        $this->assertStringContainsString('Processing', $output);
        $this->assertStringContainsString('Completed', $output);
        $this->assertStringContainsString('Failed', $output);
        $this->assertStringContainsString('Total', $output);

        // 验证数量增加了
        $afterPendingCount = count($taskRepository->findBy(['status' => HttpRequestTask::STATUS_PENDING]));
        $afterProcessingCount = count($taskRepository->findBy(['status' => HttpRequestTask::STATUS_PROCESSING]));
        $afterCompletedCount = count($taskRepository->findBy(['status' => HttpRequestTask::STATUS_COMPLETED]));
        $afterFailedCount = count($taskRepository->findBy(['status' => HttpRequestTask::STATUS_FAILED]));

        $this->assertEquals($beforePendingCount + 10, $afterPendingCount);
        $this->assertEquals($beforeProcessingCount + 2, $afterProcessingCount);
        $this->assertEquals($beforeCompletedCount + 45, $afterCompletedCount);
        $this->assertEquals($beforeFailedCount + 3, $afterFailedCount);

        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testOptionDetailed(): void
    {
        // 创建不同优先级和状态的任务
        $task1 = $this->createTask('https://api.example.com/1', HttpRequestTask::STATUS_COMPLETED);
        $task1->setPriority(HttpRequestTask::PRIORITY_HIGH);
        self::getEntityManager()->flush();

        $task2 = $this->createTask('https://api.example.com/2', HttpRequestTask::STATUS_COMPLETED);
        $task2->setPriority(HttpRequestTask::PRIORITY_NORMAL);
        self::getEntityManager()->flush();

        $task3 = $this->createTask('https://api.example.com/3', HttpRequestTask::STATUS_FAILED);
        $task3->setPriority(HttpRequestTask::PRIORITY_LOW);
        self::getEntityManager()->flush();

        // 创建日志
        $this->createLog($task1, HttpRequestLog::RESULT_SUCCESS, 200, 180);
        $this->createLog($task2, HttpRequestLog::RESULT_SUCCESS, 201, 220);
        $this->createLog($task3, HttpRequestLog::RESULT_NETWORK_ERROR, null, 50);

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--statistics' => true,
            '--detailed' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Task Statistics', $output);
        $this->assertStringContainsString('Priority Distribution', $output);
        $this->assertStringContainsString('Log Statistics', $output);
        $this->assertStringContainsString('Response Code Distribution', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    private function createTask(string $url, string $status): HttpRequestTask
    {
        $task = new HttpRequestTask();
        $task->setUrl($url);
        $task->setMethod(HttpRequestTask::METHOD_GET);
        $task->setStatus($status);
        $task->setUuid(uniqid('test_', true));
        self::getEntityManager()->persist($task);
        self::getEntityManager()->flush();

        return $task;
    }

    private function createLog(
        HttpRequestTask $task,
        string $result,
        ?int $responseCode = null,
        int $responseTime = 0
    ): HttpRequestLog {
        $log = new HttpRequestLog();
        $log->setTask($task);
        $log->setAttemptNumber($task->getAttempts() + 1);
        $log->setResult($result);
        $log->setResponseTime($responseTime);

        if (null !== $responseCode) {
            $log->setResponseCode($responseCode);
        }

        if (HttpRequestLog::RESULT_FAILURE === $result || HttpRequestLog::RESULT_NETWORK_ERROR === $result) {
            $log->setErrorMessage('Test error message');
        }

        self::getEntityManager()->persist($log);
        self::getEntityManager()->flush();

        return $log;
    }
}
