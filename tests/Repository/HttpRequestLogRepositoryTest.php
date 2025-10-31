<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestLog;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;
use Tourze\HttpRequestTaskBundle\Repository\HttpRequestLogRepository;
use Tourze\PHPUnitSymfonyKernelTest\AbstractRepositoryTestCase;

/**
 * @internal
 */
#[CoversClass(HttpRequestLogRepository::class)]
#[RunTestsInSeparateProcesses]
final class HttpRequestLogRepositoryTest extends AbstractRepositoryTestCase
{
    private HttpRequestLogRepository $repository;

    protected function onSetUp(): void
    {
        $this->repository = self::getService(HttpRequestLogRepository::class);
    }

    protected function createNewEntity(): HttpRequestLog
    {
        $task = new HttpRequestTask();
        $task->setUrl('https://api.example.com/test');
        $task->setMethod(HttpRequestTask::METHOD_GET);
        $task->setStatus(HttpRequestTask::STATUS_PENDING);

        $log = new HttpRequestLog();
        $log->setTask($task);
        $log->setAttemptNumber(1);
        $log->setResult(HttpRequestLog::RESULT_SUCCESS);
        $log->setExecutedTime(new \DateTimeImmutable());

        return $log;
    }

    protected function getRepository(): HttpRequestLogRepository
    {
        return $this->repository;
    }

    public function testSaveAndRemove(): void
    {
        $task = new HttpRequestTask();
        $task->setUrl('https://api.example.com/test');
        $task->setMethod(HttpRequestTask::METHOD_GET);

        self::getEntityManager()->persist($task);
        self::getEntityManager()->flush();

        $log = new HttpRequestLog();
        $log->setTask($task);
        $log->setAttemptNumber(1);
        $log->setResult(HttpRequestLog::RESULT_SUCCESS);
        $log->setResponseCode(200);
        $log->setResponseTime(150);
        $log->setExecutedTime(new \DateTimeImmutable());

        $this->repository->save($log, true);

        $logId = $log->getId();
        $this->assertNotNull($logId);

        $savedLog = $this->repository->find($logId);
        $this->assertNotNull($savedLog);
        $this->assertSame($task, $savedLog->getTask());
        $this->assertSame(1, $savedLog->getAttemptNumber());
        $this->assertSame(HttpRequestLog::RESULT_SUCCESS, $savedLog->getResult());
        $this->assertSame(200, $savedLog->getResponseCode());

        $this->repository->remove($log, true);
        $removedLog = $this->repository->find($logId);
        $this->assertNull($removedLog);
    }

    public function testFindByTask(): void
    {
        $task = new HttpRequestTask();
        $task->setUrl('https://api.example.com/task');
        $task->setMethod(HttpRequestTask::METHOD_POST);

        self::getEntityManager()->persist($task);

        $log1 = new HttpRequestLog();
        $log1->setTask($task);
        $log1->setAttemptNumber(1);
        $log1->setResult(HttpRequestLog::RESULT_FAILURE);
        $log1->setResponseCode(500);
        $log1->setExecutedTime(new \DateTimeImmutable('2024-01-01 10:00:00'));

        $log2 = new HttpRequestLog();
        $log2->setTask($task);
        $log2->setAttemptNumber(2);
        $log2->setResult(HttpRequestLog::RESULT_SUCCESS);
        $log2->setResponseCode(200);
        $log2->setExecutedTime(new \DateTimeImmutable('2024-01-01 10:01:00'));

        self::getEntityManager()->persist($log1);
        self::getEntityManager()->persist($log2);
        self::getEntityManager()->flush();

        $logs = $this->repository->findByTask($task);

        $this->assertCount(2, $logs);
        $this->assertSame(1, $logs[0]->getAttemptNumber());
        $this->assertSame(2, $logs[1]->getAttemptNumber());
    }

    public function testFindRecentLogs(): void
    {
        $task = new HttpRequestTask();
        $task->setUrl('https://api.example.com/recent');
        $task->setMethod(HttpRequestTask::METHOD_GET);

        self::getEntityManager()->persist($task);

        $oldLog = new HttpRequestLog();
        $oldLog->setTask($task);
        $oldLog->setAttemptNumber(1);
        $oldLog->setResult(HttpRequestLog::RESULT_SUCCESS);
        $oldLog->setExecutedTime(new \DateTimeImmutable('2024-01-01 10:00:00'));

        $recentLog = new HttpRequestLog();
        $recentLog->setTask($task);
        $recentLog->setAttemptNumber(1);
        $recentLog->setResult(HttpRequestLog::RESULT_SUCCESS);
        $recentLog->setExecutedTime(new \DateTimeImmutable());

        self::getEntityManager()->persist($oldLog);
        self::getEntityManager()->persist($recentLog);
        self::getEntityManager()->flush();

        $logs = $this->repository->findRecentLogs(10);

        $this->assertGreaterThanOrEqual(1, count($logs));
        $this->assertSame($recentLog->getId(), $logs[0]->getId());
    }

    public function testFindFailedLogs(): void
    {
        $task = new HttpRequestTask();
        $task->setUrl('https://api.example.com/failed');
        $task->setMethod(HttpRequestTask::METHOD_GET);

        self::getEntityManager()->persist($task);

        $successLog = new HttpRequestLog();
        $successLog->setTask($task);
        $successLog->setAttemptNumber(1);
        $successLog->setResult(HttpRequestLog::RESULT_SUCCESS);
        $successLog->setExecutedTime(new \DateTimeImmutable());

        $failedLog = new HttpRequestLog();
        $failedLog->setTask($task);
        $failedLog->setAttemptNumber(1);
        $failedLog->setResult(HttpRequestLog::RESULT_FAILURE);
        $failedLog->setExecutedTime(new \DateTimeImmutable());

        self::getEntityManager()->persist($successLog);
        self::getEntityManager()->persist($failedLog);
        self::getEntityManager()->flush();

        $logs = $this->repository->findFailedLogs(10);

        $failedLogFound = false;
        foreach ($logs as $log) {
            if ($log->getId() === $failedLog->getId()) {
                $failedLogFound = true;
                break;
            }
        }

        $this->assertTrue($failedLogFound);
        $this->assertNotSame(HttpRequestLog::RESULT_SUCCESS, $failedLog->getResult());
    }

    public function testGetLatestLogForTask(): void
    {
        $task = new HttpRequestTask();
        $task->setUrl('https://api.example.com/latest');
        $task->setMethod(HttpRequestTask::METHOD_GET);

        self::getEntityManager()->persist($task);

        $log1 = new HttpRequestLog();
        $log1->setTask($task);
        $log1->setAttemptNumber(1);
        $log1->setResult(HttpRequestLog::RESULT_FAILURE);
        $log1->setExecutedTime(new \DateTimeImmutable('2024-01-01 10:00:00'));

        $log2 = new HttpRequestLog();
        $log2->setTask($task);
        $log2->setAttemptNumber(2);
        $log2->setResult(HttpRequestLog::RESULT_SUCCESS);
        $log2->setExecutedTime(new \DateTimeImmutable('2024-01-01 10:01:00'));

        self::getEntityManager()->persist($log1);
        self::getEntityManager()->persist($log2);
        self::getEntityManager()->flush();

        $latestLog = $this->repository->getLatestLogForTask($task);

        $this->assertNotNull($latestLog);
        $this->assertSame(2, $latestLog->getAttemptNumber());
        $this->assertSame(HttpRequestLog::RESULT_SUCCESS, $latestLog->getResult());
    }

    public function testFindExpiredLogs(): void
    {
        $task = new HttpRequestTask();
        $task->setUrl('https://api.example.com/expired');
        $task->setMethod(HttpRequestTask::METHOD_GET);

        self::getEntityManager()->persist($task);

        // 创建一个旧的日志记录 - 使用反射设置createdTime
        $oldLog = new HttpRequestLog();
        $oldLog->setTask($task);
        $oldLog->setAttemptNumber(1);
        $oldLog->setResult(HttpRequestLog::RESULT_SUCCESS);
        $oldLog->setExecutedTime(new \DateTimeImmutable('2023-01-01 10:00:00'));

        // 使用反射设置 createdTime
        /** @phpstan-ignore-next-line */
        $reflection = new \ReflectionClass($oldLog);
        $property = $reflection->getProperty('createdTime');
        $property->setAccessible(true);
        $property->setValue($oldLog, new \DateTimeImmutable('2023-01-01 10:00:00'));

        // 创建一个最近的日志记录
        $recentLog = new HttpRequestLog();
        $recentLog->setTask($task);
        $recentLog->setAttemptNumber(2);
        $recentLog->setResult(HttpRequestLog::RESULT_SUCCESS);
        $recentLog->setExecutedTime(new \DateTimeImmutable('2023-06-01 10:00:00'));

        // 使用反射设置 createdTime
        $property->setValue($recentLog, new \DateTimeImmutable('2023-06-01 10:00:00'));

        self::getEntityManager()->persist($oldLog);
        self::getEntityManager()->persist($recentLog);
        self::getEntityManager()->flush();

        // 使用一个介于两者之间的时间作为过期时间
        $before = new \DateTimeImmutable('2024-01-01');
        $logs = $this->repository->findExpiredLogs($before, 10);

        $this->assertIsArray($logs);
        $this->assertGreaterThanOrEqual(2, count($logs));
    }

    public function testDeleteOldLogs(): void
    {
        $task = new HttpRequestTask();
        $task->setUrl('https://api.example.com/old');
        $task->setMethod(HttpRequestTask::METHOD_GET);

        self::getEntityManager()->persist($task);

        $oldLog = new HttpRequestLog();
        $oldLog->setTask($task);
        $oldLog->setAttemptNumber(1);
        $oldLog->setResult(HttpRequestLog::RESULT_SUCCESS);
        $oldLog->setExecutedTime(new \DateTimeImmutable('2023-01-01 10:00:00'));

        // 使用反射设置 createdTime 为旧日期
        /** @phpstan-ignore-next-line */
        $reflection = new \ReflectionClass($oldLog);
        $property = $reflection->getProperty('createdTime');
        $property->setAccessible(true);
        $property->setValue($oldLog, new \DateTimeImmutable('2023-01-01 10:00:00'));

        $recentLog = new HttpRequestLog();
        $recentLog->setTask($task);
        $recentLog->setAttemptNumber(2);
        $recentLog->setResult(HttpRequestLog::RESULT_SUCCESS);
        $recentLog->setExecutedTime(new \DateTimeImmutable());

        // recentLog 的 createdTime 保持默认（当前时间）

        self::getEntityManager()->persist($oldLog);
        self::getEntityManager()->persist($recentLog);
        self::getEntityManager()->flush();

        $oldLogId = $oldLog->getId();
        $recentLogId = $recentLog->getId();

        $before = new \DateTimeImmutable('2024-01-01');
        $deletedCount = $this->repository->deleteOldLogs($before);

        $this->assertGreaterThanOrEqual(1, $deletedCount);

        self::getEntityManager()->clear();
        $deletedOldLog = $this->repository->find($oldLogId);
        $this->assertNull($deletedOldLog);

        $remainingLog = $this->repository->find($recentLogId);
        $this->assertNotNull($remainingLog);
    }

    public function testFindByResult(): void
    {
        $task = new HttpRequestTask();
        $task->setUrl('https://api.example.com/byresult');
        $task->setMethod(HttpRequestTask::METHOD_GET);

        self::getEntityManager()->persist($task);

        $successLog = new HttpRequestLog();
        $successLog->setTask($task);
        $successLog->setAttemptNumber(1);
        $successLog->setResult(HttpRequestLog::RESULT_SUCCESS);
        $successLog->setExecutedTime(new \DateTimeImmutable());

        $failureLog = new HttpRequestLog();
        $failureLog->setTask($task);
        $failureLog->setAttemptNumber(1);
        $failureLog->setResult(HttpRequestLog::RESULT_FAILURE);
        $failureLog->setExecutedTime(new \DateTimeImmutable());

        self::getEntityManager()->persist($successLog);
        self::getEntityManager()->persist($failureLog);
        self::getEntityManager()->flush();

        $successLogs = $this->repository->findByResult(HttpRequestLog::RESULT_SUCCESS, 10);
        $failureLogs = $this->repository->findByResult(HttpRequestLog::RESULT_FAILURE, 10);

        $successLogFound = false;
        foreach ($successLogs as $log) {
            if ($log->getId() === $successLog->getId()) {
                $successLogFound = true;
                break;
            }
        }

        $failureLogFound = false;
        foreach ($failureLogs as $log) {
            if ($log->getId() === $failureLog->getId()) {
                $failureLogFound = true;
                break;
            }
        }

        $this->assertTrue($successLogFound);
        $this->assertTrue($failureLogFound);
    }

    public function testGetAverageResponseTime(): void
    {
        $task = new HttpRequestTask();
        $task->setUrl('https://api.example.com/average');
        $task->setMethod(HttpRequestTask::METHOD_GET);

        self::getEntityManager()->persist($task);

        $log1 = new HttpRequestLog();
        $log1->setTask($task);
        $log1->setAttemptNumber(1);
        $log1->setResult(HttpRequestLog::RESULT_SUCCESS);
        $log1->setResponseTime(100);
        $log1->setExecutedTime(new \DateTimeImmutable());

        $log2 = new HttpRequestLog();
        $log2->setTask($task);
        $log2->setAttemptNumber(2);
        $log2->setResult(HttpRequestLog::RESULT_SUCCESS);
        $log2->setResponseTime(200);
        $log2->setExecutedTime(new \DateTimeImmutable());

        self::getEntityManager()->persist($log1);
        self::getEntityManager()->persist($log2);
        self::getEntityManager()->flush();

        $averageTime = $this->repository->getAverageResponseTime();

        $this->assertIsFloat($averageTime);
        $this->assertGreaterThan(0, $averageTime);
    }

    public function testGetResultStatistics(): void
    {
        $task = new HttpRequestTask();
        $task->setUrl('https://api.example.com/stats');
        $task->setMethod(HttpRequestTask::METHOD_GET);

        self::getEntityManager()->persist($task);

        $successLog = new HttpRequestLog();
        $successLog->setTask($task);
        $successLog->setAttemptNumber(1);
        $successLog->setResult(HttpRequestLog::RESULT_SUCCESS);
        $successLog->setExecutedTime(new \DateTimeImmutable());

        $failureLog = new HttpRequestLog();
        $failureLog->setTask($task);
        $failureLog->setAttemptNumber(1);
        $failureLog->setResult(HttpRequestLog::RESULT_FAILURE);
        $failureLog->setExecutedTime(new \DateTimeImmutable());

        self::getEntityManager()->persist($successLog);
        self::getEntityManager()->persist($failureLog);
        self::getEntityManager()->flush();

        $statistics = $this->repository->getResultStatistics();

        $this->assertIsArray($statistics);
        $this->assertArrayHasKey(HttpRequestLog::RESULT_SUCCESS, $statistics);
        $this->assertArrayHasKey(HttpRequestLog::RESULT_FAILURE, $statistics);
        $this->assertGreaterThanOrEqual(1, $statistics[HttpRequestLog::RESULT_SUCCESS]);
        $this->assertGreaterThanOrEqual(1, $statistics[HttpRequestLog::RESULT_FAILURE]);
    }

    public function testGetResponseCodeDistribution(): void
    {
        $task = new HttpRequestTask();
        $task->setUrl('https://api.example.com/distribution');
        $task->setMethod(HttpRequestTask::METHOD_GET);

        self::getEntityManager()->persist($task);

        $log200 = new HttpRequestLog();
        $log200->setTask($task);
        $log200->setAttemptNumber(1);
        $log200->setResult(HttpRequestLog::RESULT_SUCCESS);
        $log200->setResponseCode(200);
        $log200->setExecutedTime(new \DateTimeImmutable());

        $log404 = new HttpRequestLog();
        $log404->setTask($task);
        $log404->setAttemptNumber(1);
        $log404->setResult(HttpRequestLog::RESULT_FAILURE);
        $log404->setResponseCode(404);
        $log404->setExecutedTime(new \DateTimeImmutable());

        self::getEntityManager()->persist($log200);
        self::getEntityManager()->persist($log404);
        self::getEntityManager()->flush();

        $distribution = $this->repository->getResponseCodeDistribution();

        $this->assertIsArray($distribution);
        $this->assertArrayHasKey(200, $distribution);
        $this->assertArrayHasKey(404, $distribution);
        $this->assertGreaterThanOrEqual(1, $distribution[200]);
        $this->assertGreaterThanOrEqual(1, $distribution[404]);
    }

    public function testRemove(): void
    {
        $task = new HttpRequestTask();
        $task->setUrl('https://api.example.com/remove-test');
        $task->setMethod(HttpRequestTask::METHOD_GET);

        self::getEntityManager()->persist($task);
        self::getEntityManager()->flush();

        $log = new HttpRequestLog();
        $log->setTask($task);
        $log->setAttemptNumber(1);
        $log->setResult(HttpRequestLog::RESULT_SUCCESS);
        $log->setResponseCode(200);
        $log->setExecutedTime(new \DateTimeImmutable());

        $this->repository->save($log, true);
        $logId = $log->getId();
        $this->assertNotNull($logId);

        $savedLog = $this->repository->find($logId);
        $this->assertNotNull($savedLog);

        $this->repository->remove($log, true);

        $removedLog = $this->repository->find($logId);
        $this->assertNull($removedLog);
    }

    public function testCountByTask(): void
    {
        $task = new HttpRequestTask();
        $task->setUrl('https://api.example.com/count');
        $task->setMethod(HttpRequestTask::METHOD_GET);

        self::getEntityManager()->persist($task);

        $log1 = new HttpRequestLog();
        $log1->setTask($task);
        $log1->setAttemptNumber(1);
        $log1->setResult(HttpRequestLog::RESULT_FAILURE);
        $log1->setExecutedTime(new \DateTimeImmutable());

        $log2 = new HttpRequestLog();
        $log2->setTask($task);
        $log2->setAttemptNumber(2);
        $log2->setResult(HttpRequestLog::RESULT_SUCCESS);
        $log2->setExecutedTime(new \DateTimeImmutable());

        self::getEntityManager()->persist($log1);
        self::getEntityManager()->persist($log2);
        self::getEntityManager()->flush();

        $count = $this->repository->countByTask($task);

        $this->assertSame(2, $count);
    }
}
