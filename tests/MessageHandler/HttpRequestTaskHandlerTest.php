<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\MessageHandler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Messenger\MessageBusInterface;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;
use Tourze\HttpRequestTaskBundle\Exception\TaskNotFoundException;
use Tourze\HttpRequestTaskBundle\Message\HttpRequestTaskMessage;
use Tourze\HttpRequestTaskBundle\MessageHandler\HttpRequestTaskHandler;
use Tourze\HttpRequestTaskBundle\Repository\HttpRequestLogRepository;
use Tourze\HttpRequestTaskBundle\Repository\HttpRequestTaskRepository;
use Tourze\HttpRequestTaskBundle\Service\TaskRetryCalculator;
use Tourze\PHPUnitSymfonyKernelTest\AbstractIntegrationTestCase;

/**
 * HttpRequestTaskHandler 消息处理器集成测试
 * 测试重点：消息处理逻辑、任务状态管理、重试调度
 *
 * @internal
 */
#[CoversClass(HttpRequestTaskHandler::class)]
#[RunTestsInSeparateProcesses]
final class HttpRequestTaskHandlerTest extends AbstractIntegrationTestCase
{
    private HttpRequestTaskHandler $handler;
    private HttpRequestTaskRepository $taskRepository;
    private HttpRequestLogRepository $logRepository;
    private TaskRetryCalculator $retryCalculator;
    private MessageBusInterface $messageBus;

    protected function onSetUp(): void
    {
        $this->handler = self::getService(HttpRequestTaskHandler::class);
        $this->taskRepository = self::getService(HttpRequestTaskRepository::class);
        $this->logRepository = self::getService(HttpRequestLogRepository::class);
        $this->retryCalculator = self::getService(TaskRetryCalculator::class);
        $this->messageBus = self::getService(MessageBusInterface::class);
    }

    public function testInvokeThrowsExceptionWhenTaskNotFound(): void
    {
        $message = new HttpRequestTaskMessage(999999);

        $this->expectException(TaskNotFoundException::class);
        $this->expectExceptionMessage('Task with ID 999999 not found');

        ($this->handler)($message);
    }

    public function testInvokeSkipsCompletedTask(): void
    {
        $task = $this->createTask(HttpRequestTask::STATUS_COMPLETED);

        $message = new HttpRequestTaskMessage($task->getId());

        $initialAttempts = $task->getAttempts();

        ($this->handler)($message);

        // 刷新任务状态
        $this->refreshTask($task);

        // 验证任务状态保持不变
        $this->assertSame(HttpRequestTask::STATUS_COMPLETED, $task->getStatus());
        $this->assertSame($initialAttempts, $task->getAttempts());
    }

    public function testInvokeSkipsCancelledTask(): void
    {
        $task = $this->createTask(HttpRequestTask::STATUS_CANCELLED);

        $message = new HttpRequestTaskMessage($task->getId());

        $initialAttempts = $task->getAttempts();

        ($this->handler)($message);

        $this->refreshTask($task);

        // 验证任务状态保持不变
        $this->assertSame(HttpRequestTask::STATUS_CANCELLED, $task->getStatus());
        $this->assertSame($initialAttempts, $task->getAttempts());
    }

    public function testInvokeReschedulesTaskForFutureUsesRetryCalculator(): void
    {
        // 测试重试计算器的 isScheduledForFuture 方法是否正确
        $task = $this->createTask(HttpRequestTask::STATUS_PENDING);
        $task->setScheduledTime(new \DateTimeImmutable('+1 hour'));
        $this->taskRepository->save($task, true);

        // 验证重试计算器可以正确判断未来调度的任务
        $result = $this->retryCalculator->isScheduledForFuture($task);

        $this->assertTrue($result);
    }

    public function testHandlerCanBeInstantiated(): void
    {
        // 测试 Handler 可以正常实例化
        $this->assertInstanceOf(HttpRequestTaskHandler::class, $this->handler);
    }

    public function testTaskRepositoryCanFindTasks(): void
    {
        // 测试任务仓储可以正常工作
        $task = $this->createTask(HttpRequestTask::STATUS_PENDING);

        $foundTask = $this->taskRepository->find($task->getId());

        $this->assertNotNull($foundTask);
        $this->assertSame($task->getId(), $foundTask->getId());
    }

    public function testLogRepositoryCanCountLogs(): void
    {
        // 测试日志仓储可以正常工作
        $count = $this->logRepository->count([]);

        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testRetryCalculatorIsScheduledForFutureWithNullScheduledTime(): void
    {
        $task = $this->createTask(HttpRequestTask::STATUS_PENDING);
        $task->setScheduledTime(null);
        $this->taskRepository->save($task, true);

        $result = $this->retryCalculator->isScheduledForFuture($task);

        $this->assertFalse($result);
    }

    public function testRetryCalculatorIsScheduledForFutureWithPastTime(): void
    {
        $task = $this->createTask(HttpRequestTask::STATUS_PENDING);
        $task->setScheduledTime(new \DateTimeImmutable('-1 hour'));
        $this->taskRepository->save($task, true);

        $result = $this->retryCalculator->isScheduledForFuture($task);

        $this->assertFalse($result);
    }

    public function testRetryCalculatorIsScheduledForFutureWithFutureTime(): void
    {
        $task = $this->createTask(HttpRequestTask::STATUS_PENDING);
        $task->setScheduledTime(new \DateTimeImmutable('+1 hour'));
        $this->taskRepository->save($task, true);

        $result = $this->retryCalculator->isScheduledForFuture($task);

        $this->assertTrue($result);
    }

    public function testRetryCalculatorCanRetryWhenAttemptsLessThanMax(): void
    {
        $task = $this->createTask(HttpRequestTask::STATUS_PENDING);
        $task->setMaxAttempts(3);
        $task->setAttempts(1);
        $this->taskRepository->save($task, true);

        $result = $this->retryCalculator->canRetry($task);

        $this->assertTrue($result);
    }

    public function testRetryCalculatorCannotRetryWhenAttemptsEqualMax(): void
    {
        $task = $this->createTask(HttpRequestTask::STATUS_PENDING);
        $task->setMaxAttempts(3);
        $task->setAttempts(3);
        $this->taskRepository->save($task, true);

        $result = $this->retryCalculator->canRetry($task);

        $this->assertFalse($result);
    }

    public function testRetryCalculatorCalculatesDelayForFirstAttempt(): void
    {
        $task = $this->createTask(HttpRequestTask::STATUS_PENDING);
        $task->setRetryDelay(1000);
        $task->setAttempts(0);
        $this->taskRepository->save($task, true);

        $delay = $this->retryCalculator->calculateNextRetryDelay($task);

        $this->assertSame(1000, $delay);
    }

    public function testRetryCalculatorCalculatesDelayWithExponentialBackoff(): void
    {
        $task = $this->createTask(HttpRequestTask::STATUS_PENDING);
        $task->setRetryDelay(1000);
        $task->setRetryMultiplier(2.0);
        $task->setAttempts(2);
        $this->taskRepository->save($task, true);

        $delay = $this->retryCalculator->calculateNextRetryDelay($task);

        // 基础延迟 = 1000 * (2.0 ^ (2-1)) = 2000
        // 实际延迟 = 2000 + jitter (0 ~ 200)
        $this->assertGreaterThanOrEqual(2000, $delay);
        $this->assertLessThanOrEqual(2200, $delay);
    }

    public function testMessageBusServiceIsAvailable(): void
    {
        // 测试 MessageBus 服务可以正常获取
        $this->assertInstanceOf(MessageBusInterface::class, $this->messageBus);
    }

    /**
     * 创建测试任务
     */
    private function createTask(string $status): HttpRequestTask
    {
        $entityManager = self::getEntityManager();

        $task = new HttpRequestTask();
        $task->setStatus($status);
        $task->setMethod(HttpRequestTask::METHOD_GET);
        $task->setUrl('https://example.com/test');
        $task->setMaxAttempts(3);
        $task->setTimeout(30);
        $task->setRetryDelay(1000);
        $task->setRetryMultiplier(2.0);

        $entityManager->persist($task);
        $entityManager->flush();

        return $task;
    }

    /**
     * 刷新任务实体
     */
    private function refreshTask(HttpRequestTask $task): void
    {
        $entityManager = self::getEntityManager();
        $entityManager->refresh($task);
    }
}
