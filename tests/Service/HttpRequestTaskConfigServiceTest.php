<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tourze\HttpRequestTaskBundle\Service\HttpRequestTaskConfigService;

/**
 * @internal
 */
#[CoversClass(HttpRequestTaskConfigService::class)]
final class HttpRequestTaskConfigServiceTest extends TestCase
{
    private HttpRequestTaskConfigService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new HttpRequestTaskConfigService();
    }

    public function testGetDefaultMaxAttempts(): void
    {
        $_ENV['HTTP_TASK_MAX_ATTEMPTS'] = '5';
        $this->assertEquals(5, $this->service->getDefaultMaxAttempts());

        unset($_ENV['HTTP_TASK_MAX_ATTEMPTS']);
        $this->assertEquals(3, $this->service->getDefaultMaxAttempts());
    }

    public function testGetDefaultTimeout(): void
    {
        $_ENV['HTTP_TASK_TIMEOUT'] = '60';
        $this->assertEquals(60, $this->service->getDefaultTimeout());

        unset($_ENV['HTTP_TASK_TIMEOUT']);
        $this->assertEquals(30, $this->service->getDefaultTimeout());
    }

    public function testGetDefaultRetryDelay(): void
    {
        $_ENV['HTTP_TASK_RETRY_DELAY'] = '2000';
        $this->assertEquals(2000, $this->service->getDefaultRetryDelay());

        unset($_ENV['HTTP_TASK_RETRY_DELAY']);
        $this->assertEquals(1000, $this->service->getDefaultRetryDelay());
    }

    public function testGetDefaultRetryMultiplier(): void
    {
        $_ENV['HTTP_TASK_RETRY_MULTIPLIER'] = '3.5';
        $this->assertEquals(3.5, $this->service->getDefaultRetryMultiplier());

        unset($_ENV['HTTP_TASK_RETRY_MULTIPLIER']);
        $this->assertEquals(2.0, $this->service->getDefaultRetryMultiplier());
    }

    public function testGetMessengerTransportName(): void
    {
        $_ENV['HTTP_TASK_TRANSPORT'] = 'sync';
        $this->assertEquals('sync', $this->service->getMessengerTransportName());

        unset($_ENV['HTTP_TASK_TRANSPORT']);
        $this->assertEquals('async', $this->service->getMessengerTransportName());
    }

    public function testIsRateLimiterEnabled(): void
    {
        $_ENV['HTTP_TASK_RATE_LIMITER_ENABLED'] = 'false';
        $this->assertFalse($this->service->isRateLimiterEnabled());

        $_ENV['HTTP_TASK_RATE_LIMITER_ENABLED'] = '0';
        $this->assertFalse($this->service->isRateLimiterEnabled());

        $_ENV['HTTP_TASK_RATE_LIMITER_ENABLED'] = 'true';
        $this->assertTrue($this->service->isRateLimiterEnabled());

        unset($_ENV['HTTP_TASK_RATE_LIMITER_ENABLED']);
        $this->assertTrue($this->service->isRateLimiterEnabled());
    }

    public function testGetRateLimiterDefaultLimit(): void
    {
        $_ENV['HTTP_TASK_RATE_LIMIT'] = '20';
        $this->assertEquals(20, $this->service->getRateLimiterDefaultLimit());

        unset($_ENV['HTTP_TASK_RATE_LIMIT']);
        $this->assertEquals(10, $this->service->getRateLimiterDefaultLimit());
    }
}
