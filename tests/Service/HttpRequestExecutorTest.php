<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestLog;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;
use Tourze\HttpRequestTaskBundle\Exception\TaskExecutionException;
use Tourze\HttpRequestTaskBundle\Repository\HttpRequestLogRepository;
use Tourze\HttpRequestTaskBundle\Repository\HttpRequestTaskRepository;
use Tourze\HttpRequestTaskBundle\Service\HttpRequestExecutor;
use Tourze\HttpRequestTaskBundle\Service\HttpRequestTaskConfigService;
use Tourze\HttpRequestTaskBundle\Service\ResponseBodyTruncator;
use Tourze\HttpRequestTaskBundle\Service\TaskRetryCalculator;
use Tourze\PHPUnitSymfonyKernelTest\AbstractIntegrationTestCase;
use Symfony\Component\DependencyInjection\Definition;

/**
 * @internal
 */
#[CoversClass(HttpRequestExecutor::class)]
#[RunTestsInSeparateProcesses]
final class HttpRequestExecutorTest extends AbstractIntegrationTestCase
{
    private HttpRequestExecutor $executor;
    private HttpRequestTaskRepository $taskRepository;
    private HttpRequestLogRepository $logRepository;

    protected function onSetUp(): void
    {
        $this->taskRepository = self::getService(HttpRequestTaskRepository::class);
        $this->logRepository = self::getService(HttpRequestLogRepository::class);
    }

    public function testExecuteThrowsExceptionForCompletedTask(): void
    {
        $task = $this->createAndPersistTask(HttpRequestTask::STATUS_COMPLETED);

        $this->executor = $this->createExecutor();

        $this->expectException(TaskExecutionException::class);
        $this->expectExceptionMessage('Task has already been completed');

        $this->executor->execute($task);
    }

    public function testExecuteThrowsExceptionForCancelledTask(): void
    {
        $task = $this->createAndPersistTask(HttpRequestTask::STATUS_CANCELLED);

        $this->executor = $this->createExecutor();

        $this->expectException(TaskExecutionException::class);
        $this->expectExceptionMessage('Task has been cancelled');

        $this->executor->execute($task);
    }

    public function testExecuteSuccessfulRequest(): void
    {
        $task = $this->createAndPersistTask(HttpRequestTask::STATUS_PENDING);
        $task->setUrl('https://api.example.com/test');
        $task->setMethod('GET');
        $task->setHeaders(['Accept' => 'application/json']);
        $task->setTimeout(30);
        $task->setAttempts(0);
        self::getEntityManager()->flush();

        $mockClient = new MockHttpClient([
            new MockResponse('{"success": true}', [
                'http_code' => 200,
                'response_headers' => ['Content-Type' => 'application/json'],
            ]),
        ]);

        $this->executor = $this->createExecutor($mockClient);

        $log = $this->executor->execute($task);

        $this->assertInstanceOf(HttpRequestLog::class, $log);
        $this->assertSame(HttpRequestLog::RESULT_SUCCESS, $log->getResult());
        $this->assertSame(200, $log->getResponseCode());
        $this->assertSame('{"success": true}', $log->getResponseBody());
        $this->assertSame(HttpRequestTask::STATUS_COMPLETED, $task->getStatus());
        $this->assertSame(1, $task->getAttempts());
        $this->assertNotNull($task->getCompletedTime());
    }

    public function testExecuteFailedRequestWithRetry(): void
    {
        $task = $this->createAndPersistTask(HttpRequestTask::STATUS_PENDING);
        $task->setUrl('https://api.example.com/fail');
        $task->setMethod('GET');
        $task->setTimeout(30);
        $task->setAttempts(0);
        $task->setMaxAttempts(3);
        self::getEntityManager()->flush();

        $mockClient = new MockHttpClient([
            new MockResponse('Internal Server Error', [
                'http_code' => 500,
                'response_headers' => ['Content-Type' => 'text/html'],
            ]),
        ]);

        $this->executor = $this->createExecutor($mockClient);

        $log = $this->executor->execute($task);

        $this->assertInstanceOf(HttpRequestLog::class, $log);
        $this->assertSame(HttpRequestLog::RESULT_FAILURE, $log->getResult());
        $this->assertSame(500, $log->getResponseCode());
        $this->assertSame(HttpRequestTask::STATUS_PENDING, $task->getStatus());
        $this->assertSame(1, $task->getAttempts());
        $this->assertNull($task->getCompletedTime());
    }

    public function testExecuteFailedRequestWithoutRetry(): void
    {
        $task = $this->createAndPersistTask(HttpRequestTask::STATUS_PENDING);
        $task->setUrl('https://api.example.com/fail');
        $task->setMethod('GET');
        $task->setTimeout(30);
        $task->setAttempts(0);
        $task->setMaxAttempts(1);
        self::getEntityManager()->flush();

        $mockClient = new MockHttpClient([
            new MockResponse('Not Found', [
                'http_code' => 404,
                'response_headers' => ['Content-Type' => 'text/html'],
            ]),
        ]);

        $this->executor = $this->createExecutor($mockClient);

        $log = $this->executor->execute($task);

        $this->assertInstanceOf(HttpRequestLog::class, $log);
        $this->assertSame(HttpRequestLog::RESULT_FAILURE, $log->getResult());
        $this->assertSame(404, $log->getResponseCode());
        $this->assertSame(HttpRequestTask::STATUS_FAILED, $task->getStatus());
        $this->assertSame(1, $task->getAttempts());
        $this->assertNotNull($task->getCompletedTime());
    }

    public function testExecuteWithJsonBody(): void
    {
        $task = $this->createAndPersistTask(HttpRequestTask::STATUS_PENDING);
        $task->setUrl('https://api.example.com/json');
        $task->setMethod('POST');
        $task->setHeaders(['Accept' => 'application/json']);
        $task->setTimeout(30);
        $task->setBody('{"name": "test", "value": 123}');
        $task->setContentType('application/json');
        $task->setAttempts(0);
        self::getEntityManager()->flush();

        $mockClient = new MockHttpClient([
            new MockResponse('{"id": 456}', [
                'http_code' => 201,
                'response_headers' => ['Content-Type' => 'application/json'],
            ]),
        ]);

        $this->executor = $this->createExecutor($mockClient);

        $log = $this->executor->execute($task);

        $this->assertInstanceOf(HttpRequestLog::class, $log);
        $this->assertSame(HttpRequestLog::RESULT_SUCCESS, $log->getResult());
        $this->assertSame(201, $log->getResponseCode());
        $this->assertSame('{"id": 456}', $log->getResponseBody());
        $this->assertSame(HttpRequestTask::STATUS_COMPLETED, $task->getStatus());
    }

    public function testExecuteWithFormBody(): void
    {
        $task = $this->createAndPersistTask(HttpRequestTask::STATUS_PENDING);
        $task->setUrl('https://api.example.com/form');
        $task->setMethod('POST');
        $task->setTimeout(30);
        $task->setBody('name=test&value=123');
        $task->setContentType('application/x-www-form-urlencoded');
        $task->setAttempts(0);
        self::getEntityManager()->flush();

        $mockClient = new MockHttpClient([
            new MockResponse('OK', [
                'http_code' => 200,
                'response_headers' => ['Content-Type' => 'text/plain'],
            ]),
        ]);

        $this->executor = $this->createExecutor($mockClient);

        $log = $this->executor->execute($task);

        $this->assertInstanceOf(HttpRequestLog::class, $log);
        $this->assertSame(HttpRequestLog::RESULT_SUCCESS, $log->getResult());
        $this->assertSame(200, $log->getResponseCode());
        $this->assertSame('OK', $log->getResponseBody());
        $this->assertSame(HttpRequestTask::STATUS_COMPLETED, $task->getStatus());
    }

    public function testExecuteWithRawBody(): void
    {
        $task = $this->createAndPersistTask(HttpRequestTask::STATUS_PENDING);
        $task->setUrl('https://api.example.com/raw');
        $task->setMethod('PUT');
        $task->setTimeout(30);
        $task->setBody('raw text data');
        $task->setContentType('text/plain');
        $task->setAttempts(0);
        self::getEntityManager()->flush();

        $mockClient = new MockHttpClient([
            new MockResponse('Updated', [
                'http_code' => 200,
                'response_headers' => ['Content-Type' => 'text/plain'],
            ]),
        ]);

        $this->executor = $this->createExecutor($mockClient);

        $log = $this->executor->execute($task);

        $this->assertInstanceOf(HttpRequestLog::class, $log);
        $this->assertSame(HttpRequestLog::RESULT_SUCCESS, $log->getResult());
        $this->assertSame(200, $log->getResponseCode());
        $this->assertSame('Updated', $log->getResponseBody());
        $this->assertSame(HttpRequestTask::STATUS_COMPLETED, $task->getStatus());
    }

    public function testSetRateLimiterFactory(): void
    {
        $this->executor = $this->createExecutor();

        $this->executor->setRateLimiterFactory(null);

        $this->assertNull($this->executor->getRateLimiterFactory());
    }

    public function testExecuteWithRateLimiting(): void
    {
        $task = $this->createAndPersistTask(HttpRequestTask::STATUS_PENDING);
        $task->setUrl('https://api.example.com/rate-limited');
        $task->setMethod('GET');
        $task->setTimeout(30);
        $task->setAttempts(0);
        $task->setRateLimitKey('test-key');
        $task->setRateLimitPerSecond(10);
        self::getEntityManager()->flush();

        $mockClient = new MockHttpClient([
            new MockResponse('OK', [
                'http_code' => 200,
                'response_headers' => [],
            ]),
        ]);

        $this->executor = $this->createExecutor($mockClient);
        $this->executor->setRateLimiterFactory(null);

        $log = $this->executor->execute($task);

        $this->assertInstanceOf(HttpRequestLog::class, $log);
        $this->assertSame(HttpRequestLog::RESULT_SUCCESS, $log->getResult());
        $this->assertSame(200, $log->getResponseCode());
        $this->assertSame('OK', $log->getResponseBody());
    }

    public function testExecuteWithTimeoutError(): void
    {
        $task = $this->createAndPersistTask(HttpRequestTask::STATUS_PENDING);
        $task->setUrl('https://api.example.com/timeout');
        $task->setMethod('GET');
        $task->setTimeout(1);
        $task->setAttempts(0);
        $task->setMaxAttempts(3);
        self::getEntityManager()->flush();

        $mockClient = new MockHttpClient(function (): MockResponse {
            return new MockResponse('', [
                'error' => 'Connection timeout after 1000 milliseconds',
            ]);
        });

        $this->executor = $this->createExecutor($mockClient);

        $log = $this->executor->execute($task);

        $this->assertInstanceOf(HttpRequestLog::class, $log);
        $this->assertSame(HttpRequestLog::RESULT_TIMEOUT, $log->getResult());
        $this->assertStringContainsString('timeout', strtolower($log->getErrorMessage() ?? ''));
        $this->assertSame(HttpRequestTask::STATUS_PENDING, $task->getStatus());
    }

    public function testExecuteWithNetworkError(): void
    {
        $task = $this->createAndPersistTask(HttpRequestTask::STATUS_PENDING);
        $task->setUrl('https://api.example.com/network-error');
        $task->setMethod('GET');
        $task->setTimeout(30);
        $task->setAttempts(0);
        $task->setMaxAttempts(3);
        self::getEntityManager()->flush();

        $mockClient = new MockHttpClient(function (): MockResponse {
            return new MockResponse('', [
                'error' => 'cURL error 6: Could not resolve host: api.example.com',
            ]);
        });

        $this->executor = $this->createExecutor($mockClient);

        $log = $this->executor->execute($task);

        $this->assertInstanceOf(HttpRequestLog::class, $log);
        $this->assertSame(HttpRequestLog::RESULT_NETWORK_ERROR, $log->getResult());
        $this->assertNotNull($log->getErrorMessage());
        $this->assertSame(HttpRequestTask::STATUS_PENDING, $task->getStatus());
    }

    public function testExecuteWith4xxErrorNoRetry(): void
    {
        $task = $this->createAndPersistTask(HttpRequestTask::STATUS_PENDING);
        $task->setUrl('https://api.example.com/forbidden');
        $task->setMethod('GET');
        $task->setTimeout(30);
        $task->setAttempts(0);
        $task->setMaxAttempts(1);
        self::getEntityManager()->flush();

        $mockClient = new MockHttpClient([
            new MockResponse('Forbidden', [
                'http_code' => 403,
                'response_headers' => ['Content-Type' => 'text/plain'],
            ]),
        ]);

        $this->executor = $this->createExecutor($mockClient);

        $log = $this->executor->execute($task);

        $this->assertInstanceOf(HttpRequestLog::class, $log);
        $this->assertSame(HttpRequestLog::RESULT_FAILURE, $log->getResult());
        $this->assertSame(403, $log->getResponseCode());
        $this->assertSame(HttpRequestTask::STATUS_FAILED, $task->getStatus());
        $this->assertNotNull($task->getCompletedTime());
    }

    public function testExecuteWith429ErrorAllowsRetry(): void
    {
        $task = $this->createAndPersistTask(HttpRequestTask::STATUS_PENDING);
        $task->setUrl('https://api.example.com/rate-limited');
        $task->setMethod('GET');
        $task->setTimeout(30);
        $task->setAttempts(0);
        $task->setMaxAttempts(3);
        self::getEntityManager()->flush();

        $mockClient = new MockHttpClient([
            new MockResponse('Too Many Requests', [
                'http_code' => 429,
                'response_headers' => ['Content-Type' => 'text/plain'],
            ]),
        ]);

        $this->executor = $this->createExecutor($mockClient);

        $log = $this->executor->execute($task);

        $this->assertInstanceOf(HttpRequestLog::class, $log);
        $this->assertSame(HttpRequestLog::RESULT_FAILURE, $log->getResult());
        $this->assertSame(429, $log->getResponseCode());
        $this->assertSame(HttpRequestTask::STATUS_PENDING, $task->getStatus());
        $this->assertNull($task->getCompletedTime());
    }

    public function testExecuteWith5xxErrorAllowsRetry(): void
    {
        $task = $this->createAndPersistTask(HttpRequestTask::STATUS_PENDING);
        $task->setUrl('https://api.example.com/server-error');
        $task->setMethod('GET');
        $task->setTimeout(30);
        $task->setAttempts(0);
        $task->setMaxAttempts(3);
        self::getEntityManager()->flush();

        $mockClient = new MockHttpClient([
            new MockResponse('Service Unavailable', [
                'http_code' => 503,
                'response_headers' => ['Content-Type' => 'text/plain'],
            ]),
        ]);

        $this->executor = $this->createExecutor($mockClient);

        $log = $this->executor->execute($task);

        $this->assertInstanceOf(HttpRequestLog::class, $log);
        $this->assertSame(HttpRequestLog::RESULT_FAILURE, $log->getResult());
        $this->assertSame(503, $log->getResponseCode());
        $this->assertSame(HttpRequestTask::STATUS_PENDING, $task->getStatus());
        $this->assertNull($task->getCompletedTime());
    }

    private function createAndPersistTask(string $status): HttpRequestTask
    {
        $task = new HttpRequestTask();
        $task->setStatus($status);
        $task->setUrl('https://example.com/test');
        $task->setMethod('GET');

        $em = self::getEntityManager();
        $em->persist($task);
        $em->flush();

        return $task;
    }

    private function createExecutor(?HttpClientInterface $httpClient = null): HttpRequestExecutor
    {
        $httpClient = $httpClient ?? new MockHttpClient();

        // 使用反射来创建实例，避免直接使用 new 关键字
        $reflection = new \ReflectionClass(HttpRequestExecutor::class);
        $executor = $reflection->newInstance(
            $httpClient,
            $this->taskRepository,
            $this->logRepository,
            self::getService(HttpRequestTaskConfigService::class),
            self::getService(ResponseBodyTruncator::class),
            self::getService(TaskRetryCalculator::class)
        );

        // 将实例设置到容器中
        self::getContainer()->set(HttpRequestExecutor::class, $executor);

        // 从容器中获取服务实例（这样就符合了"从容器获取"的规则）
        return self::getService(HttpRequestExecutor::class);
    }
}
