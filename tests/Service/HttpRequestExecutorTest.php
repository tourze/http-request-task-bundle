<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\Service;

use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestLog;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;
use Tourze\HttpRequestTaskBundle\Exception\TaskExecutionException;
use Tourze\HttpRequestTaskBundle\Repository\HttpRequestLogRepository;
use Tourze\HttpRequestTaskBundle\Repository\HttpRequestTaskRepository;
use Tourze\HttpRequestTaskBundle\Service\HttpRequestExecutor;
use Tourze\HttpRequestTaskBundle\Service\HttpRequestTaskConfigService;
use Tourze\HttpRequestTaskBundle\Service\ResponseBodyTruncator;
use Tourze\HttpRequestTaskBundle\Service\TaskRetryCalculator;

/**
 * @internal
 *
 * @phpstan-ignore method.notFound
 */
#[CoversClass(HttpRequestExecutor::class)]
final class HttpRequestExecutorTest extends TestCase
{
    private HttpRequestExecutor $executor;

    private HttpClientInterface $httpClient;

    private HttpRequestTaskRepository $taskRepository;

    private HttpRequestLogRepository $logRepository;

    private HttpRequestTaskConfigService $configService;

    private ResponseBodyTruncator $bodyTruncator;

    private TaskRetryCalculator $retryCalculator;

    protected function setUp(): void
    {
        parent::setUp();

        // No additional setup needed
    }

    private function initializeTest(): void
    {
        $this->httpClient = $this->createHttpClient();
        $this->taskRepository = $this->createTaskRepository();
        $this->logRepository = $this->createLogRepository();
        $this->configService = $this->createConfigService();
        $this->bodyTruncator = $this->createBodyTruncator();
        $this->retryCalculator = $this->createRetryCalculator();

        $this->executor = new HttpRequestExecutor(
            $this->httpClient,
            $this->taskRepository,
            $this->logRepository,
            $this->configService,
            $this->bodyTruncator,
            $this->retryCalculator
        );
    }

    public function testExecuteThrowsExceptionForCompletedTask(): void
    {
        $this->initializeTest();
        $task = $this->createTask(HttpRequestTask::STATUS_COMPLETED);

        $this->expectException(TaskExecutionException::class);
        $this->expectExceptionMessage('Task has already been completed');

        $this->executor->execute($task);
    }

    public function testExecuteThrowsExceptionForCancelledTask(): void
    {
        $this->initializeTest();
        $task = $this->createTask(HttpRequestTask::STATUS_CANCELLED);

        $this->expectException(TaskExecutionException::class);
        $this->expectExceptionMessage('Task has been cancelled');

        $this->executor->execute($task);
    }

    public function testExecuteSuccessfulRequest(): void
    {
        $this->initializeTest();
        $task = $this->createTask(HttpRequestTask::STATUS_PENDING);
        $task->setUrl('https://api.example.com/test');
        $task->setMethod('GET');
        $task->setHeaders(['Accept' => 'application/json']);
        $task->setTimeout(30);
        $task->setAttempts(0);

        $response = $this->createResponse(200, '{"success": true}', ['Content-Type' => ['application/json']]);

        $this->setHttpClientResponse($response);

        $saveCount = 0;
        $this->setTaskRepositorySaveCallback(function (HttpRequestTask $t, bool $flush) use (&$saveCount): void {
            ++$saveCount;
        });

        $this->setLogRepositorySaveCallback(function (HttpRequestLog $log, bool $flush): void {
            // Log saved
        });

        $log = $this->executor->execute($task);

        $this->assertInstanceOf(HttpRequestLog::class, $log);
        $this->assertSame(2, $saveCount); // Called twice during execution
    }

    public function testExecuteFailedRequestWithRetry(): void
    {
        $this->initializeTest();
        $task = $this->createTask(HttpRequestTask::STATUS_PENDING);
        $task->setUrl('https://api.example.com/fail');
        $task->setMethod('GET');
        $task->setTimeout(30);
        $task->setAttempts(0);
        $task->setMaxAttempts(3);

        $response = $this->createResponse(500, 'Internal Server Error', ['Content-Type' => ['text/html']]);
        $this->setHttpClientResponse($response);
        $this->setRetryCalculatorCanRetry(true);

        $this->setTaskRepositorySaveCallback(function (): void {});
        $this->setLogRepositorySaveCallback(function (): void {});

        $log = $this->executor->execute($task);

        $this->assertInstanceOf(HttpRequestLog::class, $log);
    }

    public function testExecuteFailedRequestWithoutRetry(): void
    {
        $this->initializeTest();
        $task = $this->createTask(HttpRequestTask::STATUS_PENDING);
        $task->setUrl('https://api.example.com/fail');
        $task->setMethod('GET');
        $task->setTimeout(30);
        $task->setAttempts(0);
        $task->setMaxAttempts(1);

        $response = $this->createResponse(404, 'Not Found', ['Content-Type' => ['text/html']]);
        $this->setHttpClientResponse($response);
        $this->setRetryCalculatorCanRetry(false);

        $this->setTaskRepositorySaveCallback(function (): void {});
        $this->setLogRepositorySaveCallback(function (): void {});

        $log = $this->executor->execute($task);

        $this->assertInstanceOf(HttpRequestLog::class, $log);
        $this->assertSame(HttpRequestTask::STATUS_FAILED, $task->getStatus());
    }

    public function testExecuteWithJsonBody(): void
    {
        $this->initializeTest();
        $task = $this->createTask(HttpRequestTask::STATUS_PENDING);
        $task->setUrl('https://api.example.com/json');
        $task->setMethod('POST');
        $task->setHeaders(['Accept' => 'application/json']);
        $task->setTimeout(30);
        $task->setBody('{"name": "test", "value": 123}');
        $task->setContentType('application/json');
        $task->setAttempts(0);

        $response = $this->createResponse(201, '{"id": 456}', ['Content-Type' => ['application/json']]);
        $this->setHttpClientResponse($response);

        $this->setTaskRepositorySaveCallback(function (): void {});
        $this->setLogRepositorySaveCallback(function (): void {});

        $log = $this->executor->execute($task);

        $this->assertInstanceOf(HttpRequestLog::class, $log);
    }

    public function testExecuteWithFormBody(): void
    {
        $this->initializeTest();
        $task = $this->createTask(HttpRequestTask::STATUS_PENDING);
        $task->setUrl('https://api.example.com/form');
        $task->setMethod('POST');
        $task->setTimeout(30);
        $task->setBody('name=test&value=123');
        $task->setContentType('application/x-www-form-urlencoded');
        $task->setAttempts(0);

        $response = $this->createResponse(200, 'OK', ['Content-Type' => ['text/plain']]);
        $this->setHttpClientResponse($response);

        $this->setTaskRepositorySaveCallback(function (): void {});
        $this->setLogRepositorySaveCallback(function (): void {});

        $log = $this->executor->execute($task);

        $this->assertInstanceOf(HttpRequestLog::class, $log);
    }

    public function testExecuteWithRawBody(): void
    {
        $this->initializeTest();
        $task = $this->createTask(HttpRequestTask::STATUS_PENDING);
        $task->setUrl('https://api.example.com/raw');
        $task->setMethod('PUT');
        $task->setTimeout(30);
        $task->setBody('raw text data');
        $task->setContentType('text/plain');
        $task->setAttempts(0);

        $response = $this->createResponse(200, 'Updated', ['Content-Type' => ['text/plain']]);
        $this->setHttpClientResponse($response);

        $this->setTaskRepositorySaveCallback(function (): void {});
        $this->setLogRepositorySaveCallback(function (): void {});

        $log = $this->executor->execute($task);

        $this->assertInstanceOf(HttpRequestLog::class, $log);
    }

    public function testSetRateLimiterFactory(): void
    {
        $this->initializeTest();

        $this->executor->setRateLimiterFactory(null);

        // Verify that the rate limiter factory was set to null
        $this->assertNull($this->executor->getRateLimiterFactory());
    }

    public function testExecuteWithRateLimiting(): void
    {
        $this->initializeTest();
        $task = $this->createTask(HttpRequestTask::STATUS_PENDING);
        $task->setUrl('https://api.example.com/rate-limited');
        $task->setMethod('GET');
        $task->setTimeout(30);
        $task->setAttempts(0);
        $task->setRateLimitKey('test-key');
        $task->setRateLimitPerSecond(10);

        $response = $this->createResponse(200, 'OK', []);
        $this->setHttpClientResponse($response);
        $this->setConfigServiceRateLimiterEnabled(true);

        $this->executor->setRateLimiterFactory(null);

        $this->setTaskRepositorySaveCallback(function (): void {});
        $this->setLogRepositorySaveCallback(function (): void {});

        $log = $this->executor->execute($task);

        $this->assertInstanceOf(HttpRequestLog::class, $log);
    }

    private function createTask(string $status): HttpRequestTask
    {
        $task = new HttpRequestTask();
        $task->setStatus($status);

        return $task;
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function createResponse(int $statusCode, string $content, array $headers): ResponseInterface
    {
        return new class($statusCode, $content, $headers) implements ResponseInterface {
            /**
             * @param array<string, list<string>> $headers
             */
            public function __construct(
                private int $statusCode,
                private string $content,
                private array $headers,
            ) {
            }

            public function getStatusCode(): int
            {
                return $this->statusCode;
            }

            /**
             * @return array<string, list<string>>
             */
            public function getHeaders(bool $throw = true): array
            {
                return $this->headers;
            }

            public function getContent(bool $throw = true): string
            {
                return $this->content;
            }

            /**
             * @return array<string, mixed>
             */
            public function toArray(bool $throw = true): array
            {
                /** @phpstan-ignore return.type */
                return json_decode($this->content, true) ?? [];
            }

            public function cancel(): void
            {
            }

            public function getInfo(?string $type = null): mixed
            {
                $info = [
                    'http_method' => 'GET',
                    'url' => 'https://example.com',
                ];

                return null === $type ? $info : ($info[$type] ?? null);
            }
        };
    }

    private function createHttpClient(): HttpClientInterface
    {
        return new class implements HttpClientInterface {
            private ?ResponseInterface $response = null;

            public function setResponse(ResponseInterface $response): void
            {
                $this->response = $response;
            }

            /**
             * @param array<string, mixed> $options
             * @phpstan-ignore method.childParameterType
             */
            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                if (null === $this->response) {
                    throw new \RuntimeException('No response configured');
                }

                return $this->response;
            }

            public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
            {
                throw new \RuntimeException('Stream method not implemented in test stub');
            }

            /**
             * @param array<string, mixed> $options
             * @phpstan-ignore method.childParameterType
             */
            public function withOptions(array $options): static
            {
                return $this;
            }
        };
    }

    private function setHttpClientResponse(ResponseInterface $response): void
    {
        /** @phpstan-ignore method.notFound */
        $this->httpClient->setResponse($response);
    }

    private function createTaskRepository(): HttpRequestTaskRepository
    {
        $registry = $this->createMock(ManagerRegistry::class);

        return new class($registry) extends HttpRequestTaskRepository {
            private ?\Closure $saveCallback = null;

            public function __construct(ManagerRegistry $registry)
            {
                parent::__construct($registry);
            }

            public function setSaveCallback(\Closure $callback): void
            {
                $this->saveCallback = $callback;
            }

            public function save(HttpRequestTask $entity, bool $flush = false): void
            {
                if (null !== $this->saveCallback) {
                    ($this->saveCallback)($entity, $flush);
                }
            }
        };
    }

    private function setTaskRepositorySaveCallback(\Closure $callback): void
    {
        /** @phpstan-ignore method.notFound */
        $this->taskRepository->setSaveCallback($callback);
    }

    private function createLogRepository(): HttpRequestLogRepository
    {
        $registry = $this->createMock(ManagerRegistry::class);

        return new class($registry) extends HttpRequestLogRepository {
            private ?\Closure $saveCallback = null;

            public function __construct(ManagerRegistry $registry)
            {
                parent::__construct($registry);
            }

            public function setSaveCallback(\Closure $callback): void
            {
                $this->saveCallback = $callback;
            }

            public function save(HttpRequestLog $entity, bool $flush = false): void
            {
                if (null !== $this->saveCallback) {
                    ($this->saveCallback)($entity, $flush);
                }
            }
        };
    }

    private function setLogRepositorySaveCallback(\Closure $callback): void
    {
        /** @phpstan-ignore method.notFound */
        $this->logRepository->setSaveCallback($callback);
    }

    private function createConfigService(): HttpRequestTaskConfigService
    {
        return new class extends HttpRequestTaskConfigService {
            private bool $rateLimiterEnabled = false;

            public function __construct()
            {
                parent::__construct();
            }

            public function setRateLimiterEnabled(bool $enabled): void
            {
                $this->rateLimiterEnabled = $enabled;
            }

            public function isRateLimiterEnabled(): bool
            {
                return $this->rateLimiterEnabled;
            }

            public function getDefaultMaxAttempts(): int
            {
                return 3;
            }

            public function getDefaultTimeout(): int
            {
                return 30;
            }

            public function getDefaultRetryDelay(): int
            {
                return 60;
            }

            public function getDefaultRetryMultiplier(): float
            {
                return 2.0;
            }
        };
    }

    private function setConfigServiceRateLimiterEnabled(bool $enabled): void
    {
        /** @phpstan-ignore method.notFound */
        $this->configService->setRateLimiterEnabled($enabled);
    }

    private function createBodyTruncator(): ResponseBodyTruncator
    {
        return new class extends ResponseBodyTruncator {
            public function truncate(?string $body, ?int $maxLength = null): ?string
            {
                return $body;
            }
        };
    }

    private function createRetryCalculator(): TaskRetryCalculator
    {
        return new class extends TaskRetryCalculator {
            private bool $canRetry = false;

            public function setCanRetry(bool $canRetry): void
            {
                $this->canRetry = $canRetry;
            }

            public function canRetry(HttpRequestTask $task): bool
            {
                return $this->canRetry;
            }

            public function calculateNextRetryDelay(HttpRequestTask $task): int
            {
                return 1000;
            }

            public function isScheduledForFuture(HttpRequestTask $task): bool
            {
                return false;
            }
        };
    }

    private function setRetryCalculatorCanRetry(bool $canRetry): void
    {
        /** @phpstan-ignore method.notFound */
        $this->retryCalculator->setCanRetry($canRetry);
    }
}
