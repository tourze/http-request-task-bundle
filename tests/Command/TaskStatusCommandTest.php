<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Tester\CommandTester;
use Tourze\HttpRequestTaskBundle\Command\TaskStatusCommand;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;
use Tourze\HttpRequestTaskBundle\Repository\HttpRequestLogRepository;
use Tourze\HttpRequestTaskBundle\Repository\HttpRequestTaskRepository;
use Tourze\HttpRequestTaskBundle\Service\HttpRequestTaskService;
use Tourze\PHPUnitSymfonyKernelTest\AbstractCommandTestCase;

/**
 * @internal
 */
#[CoversClass(TaskStatusCommand::class)]
#[RunTestsInSeparateProcesses]
final class TaskStatusCommandTest extends AbstractCommandTestCase
{
    private HttpRequestTaskService $taskService;

    private HttpRequestTaskRepository $taskRepository;

    private HttpRequestLogRepository $logRepository;

    protected function onSetUp(): void
    {
        $this->taskService = $this->createMock(HttpRequestTaskService::class);
        $this->taskRepository = $this->createMock(HttpRequestTaskRepository::class);
        $this->logRepository = $this->createMock(HttpRequestLogRepository::class);

        // Replace services in container
        self::getContainer()->set(HttpRequestTaskService::class, $this->taskService);
        self::getContainer()->set(HttpRequestTaskRepository::class, $this->taskRepository);
        self::getContainer()->set(HttpRequestLogRepository::class, $this->logRepository);
    }

    protected function getCommandTester(): CommandTester
    {
        $command = self::getService(TaskStatusCommand::class);

        return new CommandTester($command);
    }

    public function testExecuteShowsStatistics(): void
    {
        $stats = [
            'pending' => 10,
            'processing' => 2,
            'completed' => 50,
            'failed' => 3,
            'cancelled' => 1,
        ];

        $this->taskService->expects($this->once())
            ->method('getTaskStatistics')
            ->willReturn($stats)
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--statistics' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('HTTP Request Task Statistics', $output);
        $this->assertStringContainsString('Pending', $output);
        $this->assertStringContainsString('10', $output);
        $this->assertStringContainsString('Completed', $output);
        $this->assertStringContainsString('50', $output);
        $this->assertStringContainsString('Total', $output);
        $this->assertStringContainsString('66', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testExecuteShowsDetailedStatistics(): void
    {
        $stats = [
            'pending' => 5,
            'processing' => 1,
            'completed' => 20,
            'failed' => 2,
            'cancelled' => 0,
        ];

        $priorityDist = [
            HttpRequestTask::PRIORITY_HIGH => 8,
            HttpRequestTask::PRIORITY_NORMAL => 15,
            HttpRequestTask::PRIORITY_LOW => 5,
        ];

        $resultStats = [
            'success' => 20,
            'failure' => 2,
            'network_error' => 1,
            'timeout' => 0,
        ];

        $responseCodeDist = [
            200 => 15,
            201 => 5,
            404 => 1,
            500 => 1,
        ];

        $this->taskService->expects($this->once())
            ->method('getTaskStatistics')
            ->willReturn($stats)
        ;

        $this->taskRepository->expects($this->once())
            ->method('getPriorityDistribution')
            ->willReturn($priorityDist)
        ;

        $this->logRepository->expects($this->once())
            ->method('getResultStatistics')
            ->willReturn($resultStats)
        ;

        $this->logRepository->expects($this->once())
            ->method('getResponseCodeDistribution')
            ->willReturn($responseCodeDist)
        ;

        $this->logRepository->expects($this->once())
            ->method('getAverageResponseTime')
            ->willReturn(250.5)
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--statistics' => true,
            '--detailed' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Priority Distribution', $output);
        $this->assertStringContainsString('High', $output);
        $this->assertStringContainsString('8', $output);
        $this->assertStringContainsString('Log Statistics', $output);
        $this->assertStringContainsString('Average response time', $output);
        $this->assertStringContainsString('250.50 ms', $output);
        $this->assertStringContainsString('Response Code Distribution', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testExecuteShowsTasksByStatus(): void
    {
        $tasks = [
            $this->createMockTask(1, 'https://api.example.com/1', HttpRequestTask::STATUS_PENDING),
            $this->createMockTask(2, 'https://api.example.com/2', HttpRequestTask::STATUS_PENDING),
        ];

        $this->taskService->expects($this->once())
            ->method('findTasksByStatus')
            ->with('pending', 20)
            ->willReturn($tasks)
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--status' => 'pending',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Tasks with status "pending"', $output);
        $this->assertStringContainsString('https://api.example.com/1', $output);
        $this->assertStringContainsString('https://api.example.com/2', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testExecuteShowsAllTasks(): void
    {
        $pendingTasks = [
            $this->createMockTask(1, 'https://api.example.com/pending', HttpRequestTask::STATUS_PENDING),
        ];

        $failedTasks = [
            $this->createMockTask(2, 'https://api.example.com/failed', HttpRequestTask::STATUS_FAILED),
        ];

        $this->taskService->expects($this->once())
            ->method('findPendingTasks')
            ->with(20)
            ->willReturn($pendingTasks)
        ;

        $this->taskService->expects($this->once())
            ->method('findFailedTasks')
            ->with(20)
            ->willReturn($failedTasks)
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Pending Tasks', $output);
        $this->assertStringContainsString('Failed Tasks', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testExecuteWithNoTasks(): void
    {
        $this->taskService->expects($this->once())
            ->method('findTasksByStatus')
            ->with('completed', 20)
            ->willReturn([])
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--status' => 'completed',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('No tasks found with status "completed"', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testOptionStatus(): void
    {
        $tasks = [
            $this->createMockTask(1, 'https://api.example.com/test1', HttpRequestTask::STATUS_FAILED),
            $this->createMockTask(2, 'https://api.example.com/test2', HttpRequestTask::STATUS_FAILED),
        ];

        $this->taskService->expects($this->once())
            ->method('findTasksByStatus')
            ->with('failed', 20)
            ->willReturn($tasks)
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--status' => 'failed',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Tasks with status "failed"', $output);
        $this->assertStringContainsString('https://api.example.com/test1', $output);
        $this->assertStringContainsString('https://api.example.com/test2', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testOptionLimit(): void
    {
        $tasks = [
            $this->createMockTask(1, 'https://api.example.com/limit1', HttpRequestTask::STATUS_PENDING),
            $this->createMockTask(2, 'https://api.example.com/limit2', HttpRequestTask::STATUS_PENDING),
        ];

        $this->taskService->expects($this->once())
            ->method('findTasksByStatus')
            ->with('pending', 5)
            ->willReturn($tasks)
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--status' => 'pending',
            '--limit' => '5',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Tasks with status "pending"', $output);
        $this->assertStringContainsString('https://api.example.com/limit1', $output);
        $this->assertStringContainsString('https://api.example.com/limit2', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testOptionStatistics(): void
    {
        $statistics = [
            'pending' => 10,
            'processing' => 2,
            'completed' => 45,
            'failed' => 3,
            'cancelled' => 1,
            'total' => 61,
        ];

        $this->taskService->expects($this->once())
            ->method('getTaskStatistics')
            ->willReturn($statistics)
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--statistics' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Task Statistics', $output);
        $this->assertStringContainsString('| Pending    | 10', $output);
        $this->assertStringContainsString('| Processing | 2', $output);
        $this->assertStringContainsString('| Completed  | 45', $output);
        $this->assertStringContainsString('| Failed     | 3', $output);
        $this->assertStringContainsString('| Total      | 61', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testOptionDetailed(): void
    {
        $statistics = [
            'pending' => 5,
            'processing' => 1,
            'completed' => 20,
            'failed' => 2,
            'cancelled' => 0,
            'total' => 28,
        ];

        $this->taskService->expects($this->once())
            ->method('getTaskStatistics')
            ->willReturn($statistics)
        ;

        $commandTester = $this->getCommandTester();
        $commandTester->execute([
            '--statistics' => true,
            '--detailed' => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Task Statistics', $output);
        $this->assertStringContainsString('| Pending    | 5', $output);
        $this->assertStringContainsString('| Processing | 1', $output);
        $this->assertStringContainsString('| Completed  | 20', $output);
        $this->assertStringContainsString('| Failed     | 2', $output);
        $this->assertStringContainsString('| Total      | 28', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    private function createMockTask(int $id, string $url, string $status): HttpRequestTask
    {
        $task = $this->createMock(HttpRequestTask::class);
        $task->method('getId')->willReturn($id);
        $task->method('getUuid')->willReturn('uuid-' . $id);
        $task->method('getUrl')->willReturn($url);
        $task->method('getStatus')->willReturn($status);
        $task->method('getMethod')->willReturn('GET');
        $task->method('getAttempts')->willReturn(1);
        $task->method('getMaxAttempts')->willReturn(3);
        $task->method('getCreatedTime')->willReturn(new \DateTimeImmutable());

        return $task;
    }
}
