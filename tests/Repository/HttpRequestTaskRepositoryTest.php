<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;
use Tourze\HttpRequestTaskBundle\Repository\HttpRequestTaskRepository;
use Tourze\PHPUnitSymfonyKernelTest\AbstractRepositoryTestCase;

/**
 * @internal
 */
#[CoversClass(HttpRequestTaskRepository::class)]
#[RunTestsInSeparateProcesses]
final class HttpRequestTaskRepositoryTest extends AbstractRepositoryTestCase
{
    private HttpRequestTaskRepository $repository;

    protected function onSetUp(): void
    {
        $this->repository = self::getService(HttpRequestTaskRepository::class);
    }

    protected function createNewEntity(): HttpRequestTask
    {
        $task = new HttpRequestTask();
        $task->setUrl('https://api.example.com/test');
        $task->setMethod(HttpRequestTask::METHOD_GET);
        $task->setStatus(HttpRequestTask::STATUS_PENDING);

        return $task;
    }

    protected function getRepository(): HttpRequestTaskRepository
    {
        return $this->repository;
    }

    public function testSaveAndRemove(): void
    {
        $task = new HttpRequestTask();
        $task->setUrl('https://api.example.com/test');
        $task->setMethod(HttpRequestTask::METHOD_GET);
        $task->setStatus(HttpRequestTask::STATUS_PENDING);

        $this->repository->save($task, true);

        $taskId = $task->getId();
        $this->assertNotNull($taskId);

        $savedTask = $this->repository->find($taskId);
        $this->assertNotNull($savedTask);
        $this->assertSame('https://api.example.com/test', $savedTask->getUrl());
        $this->assertSame(HttpRequestTask::METHOD_GET, $savedTask->getMethod());
        $this->assertSame(HttpRequestTask::STATUS_PENDING, $savedTask->getStatus());

        $this->repository->remove($task, true);
        $removedTask = $this->repository->find($taskId);
        $this->assertNull($removedTask);
    }

    public function testFindPendingTasks(): void
    {
        $pendingTask = new HttpRequestTask();
        $pendingTask->setUrl('https://api.example.com/pending');
        $pendingTask->setMethod(HttpRequestTask::METHOD_GET);
        $pendingTask->setStatus(HttpRequestTask::STATUS_PENDING);
        $pendingTask->setPriority(HttpRequestTask::PRIORITY_HIGH);

        $completedTask = new HttpRequestTask();
        $completedTask->setUrl('https://api.example.com/completed');
        $completedTask->setMethod(HttpRequestTask::METHOD_GET);
        $completedTask->setStatus(HttpRequestTask::STATUS_COMPLETED);

        self::getEntityManager()->persist($pendingTask);
        self::getEntityManager()->persist($completedTask);
        self::getEntityManager()->flush();

        $tasks = $this->repository->findPendingTasks(10);

        $pendingTaskFound = false;
        foreach ($tasks as $task) {
            if ($task->getId() === $pendingTask->getId()) {
                $pendingTaskFound = true;
                $this->assertSame(HttpRequestTask::STATUS_PENDING, $task->getStatus());
                break;
            }
        }

        $this->assertTrue($pendingTaskFound);
    }

    public function testFindFailedTasks(): void
    {
        $failedTask = new HttpRequestTask();
        $failedTask->setUrl('https://api.example.com/failed');
        $failedTask->setMethod(HttpRequestTask::METHOD_GET);
        $failedTask->setStatus(HttpRequestTask::STATUS_FAILED);

        $successTask = new HttpRequestTask();
        $successTask->setUrl('https://api.example.com/success');
        $successTask->setMethod(HttpRequestTask::METHOD_GET);
        $successTask->setStatus(HttpRequestTask::STATUS_COMPLETED);

        self::getEntityManager()->persist($failedTask);
        self::getEntityManager()->persist($successTask);
        self::getEntityManager()->flush();

        $tasks = $this->repository->findFailedTasks(10);

        $failedTaskFound = false;
        foreach ($tasks as $task) {
            if ($task->getId() === $failedTask->getId()) {
                $failedTaskFound = true;
                $this->assertSame(HttpRequestTask::STATUS_FAILED, $task->getStatus());
                break;
            }
        }

        $this->assertTrue($failedTaskFound);
    }

    public function testFindTasksByStatus(): void
    {
        $processingTask = new HttpRequestTask();
        $processingTask->setUrl('https://api.example.com/processing');
        $processingTask->setMethod(HttpRequestTask::METHOD_POST);
        $processingTask->setStatus(HttpRequestTask::STATUS_PROCESSING);

        self::getEntityManager()->persist($processingTask);
        self::getEntityManager()->flush();

        $tasks = $this->repository->findTasksByStatus(HttpRequestTask::STATUS_PROCESSING, 10);

        $processingTaskFound = false;
        foreach ($tasks as $task) {
            if ($task->getId() === $processingTask->getId()) {
                $processingTaskFound = true;
                $this->assertSame(HttpRequestTask::STATUS_PROCESSING, $task->getStatus());
                break;
            }
        }

        $this->assertTrue($processingTaskFound);
    }

    public function testFindByUuid(): void
    {
        $task = new HttpRequestTask();
        $task->setUrl('https://api.example.com/uuid-test');
        $task->setMethod(HttpRequestTask::METHOD_GET);

        self::getEntityManager()->persist($task);
        self::getEntityManager()->flush();

        $uuid = $task->getUuid();

        $foundTask = $this->repository->findByUuid($uuid);

        $this->assertNotNull($foundTask);
        $this->assertSame($uuid, $foundTask->getUuid());
        $this->assertSame('https://api.example.com/uuid-test', $foundTask->getUrl());
    }

    public function testCountByStatus(): void
    {
        $task1 = new HttpRequestTask();
        $task1->setUrl('https://api.example.com/count1');
        $task1->setMethod(HttpRequestTask::METHOD_GET);
        $task1->setStatus(HttpRequestTask::STATUS_PENDING);

        $task2 = new HttpRequestTask();
        $task2->setUrl('https://api.example.com/count2');
        $task2->setMethod(HttpRequestTask::METHOD_GET);
        $task2->setStatus(HttpRequestTask::STATUS_PENDING);

        $task3 = new HttpRequestTask();
        $task3->setUrl('https://api.example.com/count3');
        $task3->setMethod(HttpRequestTask::METHOD_GET);
        $task3->setStatus(HttpRequestTask::STATUS_COMPLETED);

        self::getEntityManager()->persist($task1);
        self::getEntityManager()->persist($task2);
        self::getEntityManager()->persist($task3);
        self::getEntityManager()->flush();

        $pendingCount = $this->repository->countByStatus(HttpRequestTask::STATUS_PENDING);
        $completedCount = $this->repository->countByStatus(HttpRequestTask::STATUS_COMPLETED);

        $this->assertGreaterThanOrEqual(2, $pendingCount);
        $this->assertGreaterThanOrEqual(1, $completedCount);
    }

    public function testFindExpiredTasks(): void
    {
        $expiredTask = new HttpRequestTask();
        $expiredTask->setUrl('https://api.example.com/expired');
        $expiredTask->setMethod(HttpRequestTask::METHOD_GET);
        $expiredTask->setStatus(HttpRequestTask::STATUS_COMPLETED);

        // 使用反射设置 createdTime 为旧日期
        /** @phpstan-ignore-next-line */
        $reflection = new \ReflectionClass($expiredTask);
        $property = $reflection->getProperty('createdTime');
        $property->setAccessible(true);
        $property->setValue($expiredTask, new \DateTimeImmutable('2023-01-01 10:00:00'));

        $recentTask = new HttpRequestTask();
        $recentTask->setUrl('https://api.example.com/recent');
        $recentTask->setMethod(HttpRequestTask::METHOD_GET);
        $recentTask->setStatus(HttpRequestTask::STATUS_COMPLETED);
        // recentTask 的 createdTime 保持默认（当前时间）

        self::getEntityManager()->persist($expiredTask);
        self::getEntityManager()->persist($recentTask);
        self::getEntityManager()->flush();

        $before = new \DateTimeImmutable('2024-01-01');
        $expiredTasks = $this->repository->findExpiredTasks($before, 10);

        $expiredTaskFound = false;
        foreach ($expiredTasks as $task) {
            if ($task->getId() === $expiredTask->getId()) {
                $expiredTaskFound = true;
                break;
            }
        }

        $this->assertTrue($expiredTaskFound);
    }

    public function testFindScheduledTasks(): void
    {
        $scheduledTask = new HttpRequestTask();
        $scheduledTask->setUrl('https://api.example.com/scheduled');
        $scheduledTask->setMethod(HttpRequestTask::METHOD_GET);
        $scheduledTask->setStatus(HttpRequestTask::STATUS_PENDING);
        $scheduledTask->setScheduledTime(new \DateTimeImmutable('+1 hour'));

        $immediateTask = new HttpRequestTask();
        $immediateTask->setUrl('https://api.example.com/immediate');
        $immediateTask->setMethod(HttpRequestTask::METHOD_GET);
        $immediateTask->setStatus(HttpRequestTask::STATUS_PENDING);

        self::getEntityManager()->persist($scheduledTask);
        self::getEntityManager()->persist($immediateTask);
        self::getEntityManager()->flush();

        $scheduledTasks = $this->repository->findScheduledTasks();

        $scheduledTaskFound = false;
        foreach ($scheduledTasks as $task) {
            if ($task->getId() === $scheduledTask->getId()) {
                $scheduledTaskFound = true;
                $this->assertNotNull($task->getScheduledTime());
                break;
            }
        }

        $this->assertTrue($scheduledTaskFound);
    }

    public function testFindRetriableTasks(): void
    {
        $retriableTask = new HttpRequestTask();
        $retriableTask->setUrl('https://api.example.com/retriable');
        $retriableTask->setMethod(HttpRequestTask::METHOD_GET);
        $retriableTask->setStatus(HttpRequestTask::STATUS_FAILED);
        // attempts is set automatically in constructor
        $retriableTask->setMaxAttempts(3);
        $retriableTask->setPriority(HttpRequestTask::PRIORITY_HIGH);

        $nonRetriableTask = new HttpRequestTask();
        $nonRetriableTask->setUrl('https://api.example.com/non-retriable');
        $nonRetriableTask->setMethod(HttpRequestTask::METHOD_GET);
        $nonRetriableTask->setStatus(HttpRequestTask::STATUS_FAILED);
        // attempts is set automatically in constructor
        $nonRetriableTask->setMaxAttempts(3);

        self::getEntityManager()->persist($retriableTask);
        self::getEntityManager()->persist($nonRetriableTask);
        self::getEntityManager()->flush();

        $retriableTasks = $this->repository->findRetriableTasks(10);

        $retriableTaskFound = false;
        foreach ($retriableTasks as $task) {
            if ($task->getId() === $retriableTask->getId()) {
                $retriableTaskFound = true;
                $this->assertLessThan($task->getMaxAttempts(), $task->getAttempts());
                break;
            }
        }

        $this->assertTrue($retriableTaskFound);
    }

    public function testFindByUrl(): void
    {
        $task1 = new HttpRequestTask();
        $task1->setUrl('https://api.example.com/users/123');
        $task1->setMethod(HttpRequestTask::METHOD_GET);

        $task2 = new HttpRequestTask();
        $task2->setUrl('https://api.example.com/users/456');
        $task2->setMethod(HttpRequestTask::METHOD_GET);

        $task3 = new HttpRequestTask();
        $task3->setUrl('https://api.different.com/orders/789');
        $task3->setMethod(HttpRequestTask::METHOD_GET);

        self::getEntityManager()->persist($task1);
        self::getEntityManager()->persist($task2);
        self::getEntityManager()->persist($task3);
        self::getEntityManager()->flush();

        $usersTasks = $this->repository->findByUrl('users', 10);

        $foundTask1 = false;
        $foundTask2 = false;
        $foundTask3 = false;

        foreach ($usersTasks as $task) {
            if ($task->getId() === $task1->getId()) {
                $foundTask1 = true;
            } elseif ($task->getId() === $task2->getId()) {
                $foundTask2 = true;
            } elseif ($task->getId() === $task3->getId()) {
                $foundTask3 = true;
            }
        }

        $this->assertTrue($foundTask1);
        $this->assertTrue($foundTask2);
        $this->assertFalse($foundTask3);
    }

    public function testGetStatusStatistics(): void
    {
        $pendingTask = new HttpRequestTask();
        $pendingTask->setUrl('https://api.example.com/stats-pending');
        $pendingTask->setMethod(HttpRequestTask::METHOD_GET);
        $pendingTask->setStatus(HttpRequestTask::STATUS_PENDING);

        $completedTask = new HttpRequestTask();
        $completedTask->setUrl('https://api.example.com/stats-completed');
        $completedTask->setMethod(HttpRequestTask::METHOD_GET);
        $completedTask->setStatus(HttpRequestTask::STATUS_COMPLETED);

        $failedTask = new HttpRequestTask();
        $failedTask->setUrl('https://api.example.com/stats-failed');
        $failedTask->setMethod(HttpRequestTask::METHOD_GET);
        $failedTask->setStatus(HttpRequestTask::STATUS_FAILED);

        self::getEntityManager()->persist($pendingTask);
        self::getEntityManager()->persist($completedTask);
        self::getEntityManager()->persist($failedTask);
        self::getEntityManager()->flush();

        $statistics = $this->repository->getStatusStatistics();

        $this->assertIsArray($statistics);
        $this->assertArrayHasKey(HttpRequestTask::STATUS_PENDING, $statistics);
        $this->assertArrayHasKey(HttpRequestTask::STATUS_COMPLETED, $statistics);
        $this->assertArrayHasKey(HttpRequestTask::STATUS_FAILED, $statistics);
        $this->assertGreaterThanOrEqual(1, $statistics[HttpRequestTask::STATUS_PENDING]);
        $this->assertGreaterThanOrEqual(1, $statistics[HttpRequestTask::STATUS_COMPLETED]);
        $this->assertGreaterThanOrEqual(1, $statistics[HttpRequestTask::STATUS_FAILED]);
    }

    public function testGetPriorityDistribution(): void
    {
        $highPriorityTask = new HttpRequestTask();
        $highPriorityTask->setUrl('https://api.example.com/high');
        $highPriorityTask->setMethod(HttpRequestTask::METHOD_GET);
        $highPriorityTask->setPriority(HttpRequestTask::PRIORITY_HIGH);

        $normalPriorityTask = new HttpRequestTask();
        $normalPriorityTask->setUrl('https://api.example.com/normal');
        $normalPriorityTask->setMethod(HttpRequestTask::METHOD_GET);
        $normalPriorityTask->setPriority(HttpRequestTask::PRIORITY_NORMAL);

        $lowPriorityTask = new HttpRequestTask();
        $lowPriorityTask->setUrl('https://api.example.com/low');
        $lowPriorityTask->setMethod(HttpRequestTask::METHOD_GET);
        $lowPriorityTask->setPriority(HttpRequestTask::PRIORITY_LOW);

        self::getEntityManager()->persist($highPriorityTask);
        self::getEntityManager()->persist($normalPriorityTask);
        self::getEntityManager()->persist($lowPriorityTask);
        self::getEntityManager()->flush();

        $distribution = $this->repository->getPriorityDistribution();

        $this->assertIsArray($distribution);
        $this->assertArrayHasKey(HttpRequestTask::PRIORITY_HIGH, $distribution);
        $this->assertArrayHasKey(HttpRequestTask::PRIORITY_NORMAL, $distribution);
        $this->assertArrayHasKey(HttpRequestTask::PRIORITY_LOW, $distribution);
        $this->assertGreaterThanOrEqual(1, $distribution[HttpRequestTask::PRIORITY_HIGH]);
        $this->assertGreaterThanOrEqual(1, $distribution[HttpRequestTask::PRIORITY_NORMAL]);
        $this->assertGreaterThanOrEqual(1, $distribution[HttpRequestTask::PRIORITY_LOW]);
    }

    public function testFindByPriority(): void
    {
        $highPriorityTask = new HttpRequestTask();
        $highPriorityTask->setUrl('https://api.example.com/find-high');
        $highPriorityTask->setMethod(HttpRequestTask::METHOD_GET);
        $highPriorityTask->setPriority(HttpRequestTask::PRIORITY_HIGH);

        $normalPriorityTask = new HttpRequestTask();
        $normalPriorityTask->setUrl('https://api.example.com/find-normal');
        $normalPriorityTask->setMethod(HttpRequestTask::METHOD_GET);
        $normalPriorityTask->setPriority(HttpRequestTask::PRIORITY_NORMAL);

        self::getEntityManager()->persist($highPriorityTask);
        self::getEntityManager()->persist($normalPriorityTask);
        self::getEntityManager()->flush();

        $highPriorityTasks = $this->repository->findByPriority(HttpRequestTask::PRIORITY_HIGH, 10);

        $highPriorityTaskFound = false;
        foreach ($highPriorityTasks as $task) {
            if ($task->getId() === $highPriorityTask->getId()) {
                $highPriorityTaskFound = true;
                $this->assertSame(HttpRequestTask::PRIORITY_HIGH, $task->getPriority());
                break;
            }
        }

        $this->assertTrue($highPriorityTaskFound);
    }

    public function testFindProcessingTasks(): void
    {
        $processingTask = new HttpRequestTask();
        $processingTask->setUrl('https://api.example.com/processing');
        $processingTask->setMethod(HttpRequestTask::METHOD_POST);
        $processingTask->setStatus(HttpRequestTask::STATUS_PROCESSING);
        $processingTask->setStartedTime(new \DateTimeImmutable());

        $pendingTask = new HttpRequestTask();
        $pendingTask->setUrl('https://api.example.com/pending');
        $pendingTask->setMethod(HttpRequestTask::METHOD_GET);
        $pendingTask->setStatus(HttpRequestTask::STATUS_PENDING);

        self::getEntityManager()->persist($processingTask);
        self::getEntityManager()->persist($pendingTask);
        self::getEntityManager()->flush();

        $processingTasks = $this->repository->findProcessingTasks(10);

        $processingTaskFound = false;
        foreach ($processingTasks as $task) {
            if ($task->getId() === $processingTask->getId()) {
                $processingTaskFound = true;
                $this->assertSame(HttpRequestTask::STATUS_PROCESSING, $task->getStatus());
                break;
            }
        }

        $this->assertTrue($processingTaskFound);
    }

    public function testDeleteOldTasks(): void
    {
        $oldCompletedTask = new HttpRequestTask();
        $oldCompletedTask->setUrl('https://api.example.com/old-completed');
        $oldCompletedTask->setMethod(HttpRequestTask::METHOD_GET);
        $oldCompletedTask->setStatus(HttpRequestTask::STATUS_COMPLETED);

        $recentPendingTask = new HttpRequestTask();
        $recentPendingTask->setUrl('https://api.example.com/recent-pending');
        $recentPendingTask->setMethod(HttpRequestTask::METHOD_GET);
        $recentPendingTask->setStatus(HttpRequestTask::STATUS_PENDING);

        self::getEntityManager()->persist($oldCompletedTask);
        self::getEntityManager()->persist($recentPendingTask);
        self::getEntityManager()->flush();

        $recentPendingTaskId = $recentPendingTask->getId();

        $before = new \DateTimeImmutable('+1 day');
        $deletedCount = $this->repository->deleteOldTasks($before);

        $this->assertGreaterThanOrEqual(0, $deletedCount);

        self::getEntityManager()->clear();
        $remainingTask = $this->repository->find($recentPendingTaskId);
        $this->assertNotNull($remainingTask);
    }

    public function testRemove(): void
    {
        $task = new HttpRequestTask();
        $task->setUrl('https://api.example.com/remove-test');
        $task->setMethod(HttpRequestTask::METHOD_GET);

        $this->repository->save($task, true);
        $taskId = $task->getId();
        $this->assertNotNull($taskId);

        $savedTask = $this->repository->find($taskId);
        $this->assertNotNull($savedTask);

        $this->repository->remove($task, true);

        $removedTask = $this->repository->find($taskId);
        $this->assertNull($removedTask);
    }
}
