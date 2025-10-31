<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Type as AssertType;
use Tourze\DoctrineIndexedBundle\Attribute\IndexColumn;
use Tourze\HttpRequestTaskBundle\Repository\HttpRequestLogRepository;

#[ORM\Entity(repositoryClass: HttpRequestLogRepository::class)]
#[ORM\Table(name: 'http_request_log', options: ['comment' => 'HTTP请求执行日志'])]
class HttpRequestLog implements \Stringable
{
    public const RESULT_SUCCESS = 'success';
    public const RESULT_FAILURE = 'failure';
    public const RESULT_TIMEOUT = 'timeout';
    public const RESULT_NETWORK_ERROR = 'network_error';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER, options: ['comment' => '主键ID'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: HttpRequestTask::class, inversedBy: 'logs', cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false)]
    private HttpRequestTask $task;

    #[ORM\Column(type: Types::INTEGER, options: ['comment' => '尝试次数'])]
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    #[IndexColumn]
    private int $attemptNumber;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, options: ['comment' => '执行时间'])]
    #[Assert\NotNull]
    #[IndexColumn]
    private \DateTimeImmutable $executedTime;

    /**
     * @var array<string, string|array<string>>
     */
    #[ORM\Column(type: Types::JSON, options: ['comment' => '请求头'])]
    #[Assert\NotNull]
    #[AssertType(type: 'array<string, mixed>')]
    private array $requestHeaders = [];

    #[ORM\Column(type: Types::TEXT, nullable: true, options: ['comment' => '请求体'])]
    #[Assert\Length(max: 65535)]
    private ?string $requestBody = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true, options: ['comment' => '响应代码'])]
    #[Assert\Range(min: 100, max: 599)]
    private ?int $responseCode = null;

    /**
     * @var array<string, string|array<string>>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['comment' => '响应头'])]
    #[AssertType(type: 'array<string, mixed>')]
    private ?array $responseHeaders = null;

    #[ORM\Column(type: Types::TEXT, nullable: true, options: ['comment' => '响应体'])]
    #[Assert\Length(max: 65535)]
    private ?string $responseBody = null;

    #[ORM\Column(type: Types::INTEGER, options: ['comment' => '响应时间(毫秒)'])]
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    private int $responseTime = 0;

    #[ORM\Column(type: Types::STRING, length: 20, options: ['comment' => '执行结果'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 20)]
    #[Assert\Choice(choices: [self::RESULT_SUCCESS, self::RESULT_FAILURE, self::RESULT_TIMEOUT, self::RESULT_NETWORK_ERROR])]
    private string $result = self::RESULT_FAILURE;

    #[ORM\Column(type: Types::TEXT, nullable: true, options: ['comment' => '错误信息'])]
    #[Assert\Length(max: 65535)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, options: ['comment' => '创建时间'])]
    #[Assert\NotNull]
    private \DateTimeImmutable $createdTime;

    public function __construct()
    {
        $this->executedTime = new \DateTimeImmutable();
        $this->createdTime = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTask(): HttpRequestTask
    {
        return $this->task;
    }

    public function setTask(HttpRequestTask $task): void
    {
        $this->task = $task;
    }

    public function getAttemptNumber(): int
    {
        return $this->attemptNumber;
    }

    public function setAttemptNumber(int $attemptNumber): void
    {
        $this->attemptNumber = $attemptNumber;
    }

    public function getExecutedTime(): \DateTimeImmutable
    {
        return $this->executedTime;
    }

    public function setExecutedTime(\DateTimeImmutable $executedTime): void
    {
        $this->executedTime = $executedTime;
    }

    /**
     * @return array<string, string|array<string>>
     */
    public function getRequestHeaders(): array
    {
        return $this->requestHeaders;
    }

    /**
     * @param array<string, string|array<string>> $requestHeaders
     */
    public function setRequestHeaders(array $requestHeaders): void
    {
        $this->requestHeaders = $requestHeaders;
    }

    public function getRequestBody(): ?string
    {
        return $this->requestBody;
    }

    public function setRequestBody(?string $requestBody): void
    {
        $this->requestBody = $requestBody;
    }

    public function getResponseCode(): ?int
    {
        return $this->responseCode;
    }

    public function setResponseCode(?int $responseCode): void
    {
        $this->responseCode = $responseCode;
    }

    /**
     * @return array<string, string|array<string>>|null
     */
    public function getResponseHeaders(): ?array
    {
        return $this->responseHeaders;
    }

    /**
     * @param array<string, string|array<string>>|null $responseHeaders
     */
    public function setResponseHeaders(?array $responseHeaders): void
    {
        $this->responseHeaders = $responseHeaders;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }

    public function setResponseBody(?string $responseBody): void
    {
        $this->responseBody = $responseBody;
    }

    public function getResponseTime(): int
    {
        return $this->responseTime;
    }

    public function setResponseTime(int $responseTime): void
    {
        $this->responseTime = $responseTime;
    }

    public function getResult(): string
    {
        return $this->result;
    }

    public function setResult(string $result): void
    {
        $this->result = $result;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): void
    {
        $this->errorMessage = $errorMessage;
    }

    public function getCreatedTime(): \DateTimeImmutable
    {
        return $this->createdTime;
    }

    public function __toString(): string
    {
        return sprintf(
            'Log #%d (Task: %s, Attempt: %d, Result: %s)',
            $this->id ?? 0,
            $this->task->getUuid(),
            $this->attemptNumber,
            $this->result
        );
    }
}
