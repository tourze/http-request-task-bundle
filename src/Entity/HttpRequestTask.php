<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Expression as AssertExpression;
use Symfony\Component\Validator\Constraints\Type as AssertType;
use Tourze\DoctrineIndexedBundle\Attribute\IndexColumn;
use Tourze\HttpRequestTaskBundle\Repository\HttpRequestTaskRepository;

#[ORM\Entity(repositoryClass: HttpRequestTaskRepository::class)]
#[ORM\Table(name: 'http_request_task', options: ['comment' => 'HTTP请求任务表'])]
#[ORM\Index(name: 'http_request_task_idx_status_priority_scheduled', columns: ['status', 'priority', 'scheduled_time'])]
#[ORM\Index(name: 'http_request_task_idx_status_created', columns: ['status', 'created_time'])]
class HttpRequestTask implements \Stringable
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const PRIORITY_LOW = 1;
    public const PRIORITY_NORMAL = 2;
    public const PRIORITY_HIGH = 3;

    public const METHOD_GET = 'GET';
    public const METHOD_POST = 'POST';
    public const METHOD_PUT = 'PUT';
    public const METHOD_DELETE = 'DELETE';
    public const METHOD_PATCH = 'PATCH';
    public const METHOD_HEAD = 'HEAD';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER, options: ['comment' => '主键ID'])]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 36, unique: true, options: ['comment' => '唯一标识符'])]
    #[Assert\Length(max: 36)]
    private string $uuid;

    #[ORM\Column(type: Types::STRING, length: 20, options: ['comment' => '任务状态'])]
    #[Assert\Length(max: 20)]
    #[IndexColumn]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::STRING, length: 10, options: ['comment' => 'HTTP方法'])]
    #[Assert\Length(max: 10)]
    private string $method = self::METHOD_GET;

    #[ORM\Column(type: Types::TEXT, options: ['comment' => '请求URL'])]
    #[Assert\Url]
    #[Assert\Length(max: 65535)]
    private string $url;

    /**
     * @var array<string, string|array<string>>
     */
    #[ORM\Column(type: Types::JSON, options: ['comment' => '请求头'])]
    #[AssertType(type: 'array')]
    private array $headers = [];

    #[ORM\Column(type: Types::TEXT, nullable: true, options: ['comment' => '请求体'])]
    #[Assert\Length(max: 65535)]
    private ?string $body = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true, options: ['comment' => '内容类型'])]
    #[Assert\Length(max: 50)]
    private ?string $contentType = null;

    #[ORM\Column(type: Types::INTEGER, options: ['comment' => '优先级'])]
    #[Assert\PositiveOrZero]
    #[IndexColumn]
    private int $priority = self::PRIORITY_NORMAL;

    #[ORM\Column(type: Types::INTEGER, options: ['comment' => '最大尝试次数'])]
    #[Assert\Positive]
    private int $maxAttempts = 3;

    #[ORM\Column(type: Types::INTEGER, options: ['comment' => '已尝试次数'])]
    #[Assert\PositiveOrZero]
    private int $attempts = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['comment' => '超时时间(秒)'])]
    #[Assert\Positive]
    private int $timeout = 30;

    #[ORM\Column(type: Types::INTEGER, options: ['comment' => '重试延迟(毫秒)'])]
    #[Assert\PositiveOrZero]
    private int $retryDelay = 1000;

    #[ORM\Column(type: Types::FLOAT, options: ['comment' => '重试延迟倍数'])]
    #[Assert\Positive]
    private float $retryMultiplier = 2.0;

    #[ORM\Column(type: Types::INTEGER, nullable: true, options: ['comment' => '最后响应代码'])]
    #[Assert\Range(min: 100, max: 599)]
    private ?int $lastResponseCode = null;

    #[ORM\Column(type: Types::TEXT, nullable: true, options: ['comment' => '最后响应体'])]
    #[Assert\Length(max: 65535)]
    private ?string $lastResponseBody = null;

    #[ORM\Column(type: Types::TEXT, nullable: true, options: ['comment' => '最后错误信息'])]
    #[Assert\Length(max: 65535)]
    private ?string $lastErrorMessage = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, options: ['comment' => '计划执行时间'])]
    #[AssertExpression(expression: 'this.getScheduledTime() === null or this.getScheduledTime() >= this.getCreatedTime()', message: '计划执行时间不能早于创建时间')]
    #[IndexColumn]
    private ?\DateTimeImmutable $scheduledTime = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, options: ['comment' => '开始执行时间'])]
    #[AssertExpression(expression: 'this.getStartedTime() === null or this.getStartedTime() >= this.getCreatedTime()', message: '开始执行时间不能早于创建时间')]
    private ?\DateTimeImmutable $startedTime = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, options: ['comment' => '完成时间'])]
    #[AssertExpression(expression: 'this.getCompletedTime() === null or this.getCompletedTime() >= this.getCreatedTime()', message: '完成时间不能早于创建时间')]
    private ?\DateTimeImmutable $completedTime = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, options: ['comment' => '最后尝试时间'])]
    #[AssertExpression(expression: 'this.getLastAttemptTime() === null or this.getLastAttemptTime() >= this.getCreatedTime()', message: '最后尝试时间不能早于创建时间')]
    private ?\DateTimeImmutable $lastAttemptTime = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, options: ['comment' => '创建时间'])]
    #[Assert\NotNull]
    #[IndexColumn]
    private \DateTimeImmutable $createdTime;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, options: ['comment' => '更新时间'])]
    #[Assert\NotNull]
    private \DateTimeImmutable $updatedTime;

    /**
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['comment' => '元数据'])]
    #[AssertType(type: 'array')]
    private ?array $metadata = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true, options: ['comment' => '限流键'])]
    #[Assert\Length(max: 255)]
    private ?string $rateLimitKey = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true, options: ['comment' => '限流速率(次/秒)'])]
    #[Assert\PositiveOrZero]
    #[Assert\Range(min: 0, max: 10000)]
    private ?int $rateLimitPerSecond = null;

    /**
     * @var Collection<int, HttpRequestLog>
     */
    #[ORM\OneToMany(targetEntity: HttpRequestLog::class, mappedBy: 'task', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(value: ['createdTime' => 'DESC'])]
    private Collection $logs;

    public function __construct()
    {
        // UUID将由Service层设置，但提供临时默认值避免约束冲突
        $this->uuid = uniqid('tmp_', true);
        $this->createdTime = new \DateTimeImmutable();
        $this->updatedTime = new \DateTimeImmutable();
        $this->logs = new ArrayCollection();
    }

    public function setUuid(string $uuid): void
    {
        $this->uuid = $uuid;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function setMethod(string $method): void
    {
        $this->method = $method;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): void
    {
        $this->url = $url;
    }

    /**
     * @return array<string, string|array<string>>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * @param array<string, string|array<string>> $headers
     */
    public function setHeaders(array $headers): void
    {
        $this->headers = $headers;
    }

    /**
     * 获取请求头数量(用于EasyAdmin显示)
     */
    public function getHeaderCount(): int
    {
        return count($this->headers);
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): void
    {
        $this->body = $body;
    }

    public function getContentType(): ?string
    {
        return $this->contentType;
    }

    public function setContentType(?string $contentType): void
    {
        $this->contentType = $contentType;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): void
    {
        $this->priority = $priority;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function setMaxAttempts(int $maxAttempts): void
    {
        $this->maxAttempts = $maxAttempts;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function setAttempts(int $attempts): void
    {
        $this->attempts = $attempts;
    }

    public function setLastAttemptTime(\DateTimeImmutable $lastAttemptTime): void
    {
        $this->lastAttemptTime = $lastAttemptTime;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function setTimeout(int $timeout): void
    {
        $this->timeout = $timeout;
    }

    public function getRetryDelay(): int
    {
        return $this->retryDelay;
    }

    public function setRetryDelay(int $retryDelay): void
    {
        $this->retryDelay = $retryDelay;
    }

    public function getRetryMultiplier(): float
    {
        return $this->retryMultiplier;
    }

    public function setRetryMultiplier(float $retryMultiplier): void
    {
        $this->retryMultiplier = $retryMultiplier;
    }

    public function getLastResponseCode(): ?int
    {
        return $this->lastResponseCode;
    }

    public function setLastResponseCode(?int $lastResponseCode): void
    {
        $this->lastResponseCode = $lastResponseCode;
    }

    public function getLastResponseBody(): ?string
    {
        return $this->lastResponseBody;
    }

    public function setLastResponseBody(?string $lastResponseBody): void
    {
        $this->lastResponseBody = $lastResponseBody;
    }

    public function getLastErrorMessage(): ?string
    {
        return $this->lastErrorMessage;
    }

    public function setLastErrorMessage(?string $lastErrorMessage): void
    {
        $this->lastErrorMessage = $lastErrorMessage;
    }

    public function getScheduledTime(): ?\DateTimeImmutable
    {
        return $this->scheduledTime;
    }

    public function setScheduledTime(?\DateTimeImmutable $scheduledTime): void
    {
        $this->scheduledTime = $scheduledTime;
    }

    public function getStartedTime(): ?\DateTimeImmutable
    {
        return $this->startedTime;
    }

    public function setStartedTime(?\DateTimeImmutable $startedTime): void
    {
        $this->startedTime = $startedTime;
    }

    public function getCompletedTime(): ?\DateTimeImmutable
    {
        return $this->completedTime;
    }

    public function setCompletedTime(?\DateTimeImmutable $completedTime): void
    {
        $this->completedTime = $completedTime;
    }

    public function getLastAttemptTime(): ?\DateTimeImmutable
    {
        return $this->lastAttemptTime;
    }

    public function getCreatedTime(): \DateTimeImmutable
    {
        return $this->createdTime;
    }

    public function getUpdatedTime(): \DateTimeImmutable
    {
        return $this->updatedTime;
    }

    public function setUpdatedTime(\DateTimeImmutable $updatedTime): void
    {
        $this->updatedTime = $updatedTime;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function setMetadata(?array $metadata): void
    {
        $this->metadata = $metadata;
    }

    public function getRateLimitKey(): ?string
    {
        return $this->rateLimitKey;
    }

    public function setRateLimitKey(?string $rateLimitKey): void
    {
        $this->rateLimitKey = $rateLimitKey;
    }

    public function getRateLimitPerSecond(): ?int
    {
        return $this->rateLimitPerSecond;
    }

    public function setRateLimitPerSecond(?int $rateLimitPerSecond): void
    {
        $this->rateLimitPerSecond = $rateLimitPerSecond;
    }

    /**
     * @return Collection<int, HttpRequestLog>
     */
    public function getLogs(): Collection
    {
        return $this->logs;
    }

    public function incrementAttempts(): void
    {
        ++$this->attempts;
        $this->lastAttemptTime = new \DateTimeImmutable();
    }

    public function canRetry(): bool
    {
        return $this->attempts < $this->maxAttempts;
    }

    public function calculateNextRetryDelay(): int
    {
        if (0 === $this->attempts) {
            return $this->retryDelay;
        }

        return (int) ($this->retryDelay * ($this->retryMultiplier ** ($this->attempts - 1)));
    }

    public function isScheduledForFuture(): bool
    {
        if (null === $this->scheduledTime) {
            return false;
        }

        return $this->scheduledTime > new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return sprintf(
            'Task #%d (%s %s - %s)',
            $this->id ?? 0,
            $this->method,
            $this->url,
            $this->status
        );
    }
}
