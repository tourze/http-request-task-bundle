# HTTP Request Task Bundle

[English](README.md) | [中文](README.zh-CN.md)

一个用于在 Symfony 应用中统一创建、调度、执行与观测外部 HTTP 请求（Webhook/API 调用等）的 Bundle。内置异步处理、重试、定时执行、限流、执行日志与 EasyAdmin 后台集成。

- 支持多 HTTP 方法：GET/POST/PUT/PATCH/DELETE/HEAD
- 异步执行：基于 Messenger 分发 `HttpRequestTaskMessage`
- 重试策略：固定初始延迟 + 指数退避 + 抖动；可按任务自定义
- 定时/延迟执行：支持按时间点或延时调度
- 速率限制：可选集成 RateLimiter，支持按域名或自定义键限流
- 批量任务：命令/服务批量创建 URL/API/Webhook/定时任务
- 后台管理：EasyAdmin 实体列表、详情、重试/取消动作、日志明细
- 全量审计：保存请求/响应/耗时/错误等日志

## 安装

```bash
composer require tourze/http-request-task-bundle
```

在 `config/bundles.php` 启用（若未使用 Flex 自动启用）：

```php
return [
    // ...
    Tourze\HttpRequestTaskBundle\HttpRequestTaskBundle::class => ['all' => true],
];
```

数据库准备（示例）：

```bash
bin/console doctrine:schema:update --force
```

将创建两张表：`http_request_task`、`http_request_log`。

## 快速开始

### 通过服务创建任务

```php
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;
use Tourze\HttpRequestTaskBundle\Service\HttpRequestTaskService;

final class MyService
{
    public function __construct(private HttpRequestTaskService $tasks) {}

    public function demo(): void
    {
        // 1) 最简单的 GET
        $task = $this->tasks->createTask('https://api.example.com/ping');

        // 2) POST JSON（带优先级、超时、重试、定时）
        $task = $this->tasks->createTask(
            url: 'https://api.example.com/webhook',
            method: HttpRequestTask::METHOD_POST,
            headers: ['Content-Type' => 'application/json', 'X-Trace-Id' => 'abc-123'],
            body: json_encode(['event' => 'order.created', 'id' => 123]),
            contentType: 'application/json',
            priority: HttpRequestTask::PRIORITY_HIGH,
            options: [
                'timeout' => 60,            // 秒
                'max_attempts' => 5,        // 最大尝试次数
                'retry_delay' => 1000,      // 毫秒
                'retry_multiplier' => 2.0,  // 指数退避倍数
                'scheduled_at' => new \DateTimeImmutable('+10 minutes'),
                'metadata' => ['source' => 'my-service'],
                'rate_limit_key' => 'webhook:example', // 自定义限流键（可选）
                'rate_limit_per_second' => 10,         // 每秒上限（可选）
            ],
        );
    }
}
```

说明：

- 任务入库后自动分发到 Messenger（若设置未来时间，会按时间差延迟分发）。
- 支持的 `options` 键：
  - `max_attempts`(int) 默认 3
  - `timeout`(int, 秒) 默认 30
  - `retry_delay`(int, 毫秒) 默认 1000
  - `retry_multiplier`(float) 默认 2.0
  - `scheduled_at`(DateTimeImmutable) 计划执行时间
  - `metadata`(array)
  - `rate_limit_key`(string|null)
  - `rate_limit_per_second`(int|null)

### 批量任务

`BatchTaskService` 封装常用批量模式：

```php
use Tourze\HttpRequestTaskBundle\Service\BatchTaskService;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;

final class BatchDemo
{
    public function __construct(private BatchTaskService $batch) {}

    public function run(): void
    {
        // 1) 从 URL 列表创建
        $this->batch->createFromUrls([
            'https://api.example.com/1',
            'https://api.example.com/2',
        ], [
            'method' => HttpRequestTask::METHOD_GET,
            'priority' => HttpRequestTask::PRIORITY_NORMAL,
            'options' => ['timeout' => 20],
        ]);

        // 2) 按 API 端点数组创建（自动 JSON）
        $this->batch->createApiCalls([
            ['url' => 'https://api.example.com/users', 'data' => ['name' => 'John']],
            ['url' => 'https://api.example.com/orders', 'data' => ['id' => 100]],
        ]);

        // 3) 批量 Webhook 事件（自动设置头部与 JSON）
        $this->batch->createWebhookEvents(
            'https://webhook.example.com/events',
            [
                ['type' => 'user.created', 'id' => 'evt_1', 'data' => ['uid' => 1]],
                ['type' => 'order.completed', 'id' => 'evt_2', 'data' => ['oid' => 2]],
            ],
        );

        // 4) 定时批次（每 300s 一条，共 10 条）
        $this->batch->createScheduledBatch(
            startTime: new \DateTimeImmutable('+1 hour'),
            count: 10,
            intervalSeconds: 300,
            taskTemplate: [
                'url' => 'https://api.example.com/cron',
                'method' => HttpRequestTask::METHOD_POST,
                'options' => ['timeout' => 15],
            ],
        );
    }
}
```

## 命令行

### 任务状态

```bash
bin/console http-request-task:status
bin/console http-request-task:status --status=failed --limit=50
bin/console http-request-task:status --statistics
bin/console http-request-task:status --statistics --detailed
```

### 重试失败任务

```bash
# 重试单个任务
bin/console http-request-task:retry-failed 123

# 批量重试（最多 10 条）
bin/console http-request-task:retry-failed --limit=10

# 超过最大次数也强制重试
bin/console http-request-task:retry-failed 123 --force

# 预演（不真正执行）
bin/console http-request-task:retry-failed --dry-run
```

### 批量创建任务

```bash
# 1) 从 URL 列表（JSON 文件键名: "urls"）
bin/console http-request-task:create-batch urls urls.json

# 2) 从 API 端点定义（JSON 键名: "endpoints"，每项含 url/method/data）
bin/console http-request-task:create-batch api endpoints.json

# 3) 批量 Webhook 事件（JSON 键名: "webhook_url" + "events"）
bin/console http-request-task:create-batch webhook webhook.json

# 4) 固定 URL 的定时批量
bin/console http-request-task:create-batch scheduled https://api.example.com/cron \
  --count=10 --interval=60 --scheduled-time="2025-01-01 10:00:00"

# 通用选项
  -m, --method=METHOD           HTTP 方法（默认 GET）
  -p, --priority=high|normal|low 优先级（默认 normal）
  -t, --timeout=SECONDS         超时秒数（默认 30）
  -a, --max-attempts=NUM        最大尝试次数（默认 3）
      --dry-run                 预演模式，仅打印
```

JSON 示例：

`urls.json`

```json
{ "urls": ["https://api.example.com/1", "https://api.example.com/2"] }
```

`endpoints.json`

```json
{
  "endpoints": [
    { "url": "https://api.example.com/users",  "method": "POST", "data": {"name": "John"}},
    { "url": "https://api.example.com/orders", "method": "POST", "data": {"id": 100}}
  ]
}
```

`webhook.json`

```json
{
  "webhook_url": "https://webhook.example.com/events",
  "events": [
    {"type": "user.created", "id": "evt_1", "data": {"uid": 1}},
    {"type": "order.completed", "id": "evt_2", "data": {"oid": 2}}
  ]
}
```

> 注意：~~命令 `scheduled` 分支内部生成的任务数组使用 `options.scheduled_time` 字段，而服务端实际识别的是 `scheduled_at`。这会导致通过该命令创建的定时任务未生效。建议后续将命令实现中的 `scheduled_time` 修正为 `scheduled_at`。~~ **已修复：现在使用统一的 `scheduled_at` 字段。**

### 清理历史数据

```bash
bin/console http-request-task:cleanup
bin/console http-request-task:cleanup --days=30
bin/console http-request-task:cleanup --logs-only
bin/console http-request-task:cleanup --tasks-only
bin/console http-request-task:cleanup --dry-run
```

## 环境变量（配置服务）

`HttpRequestTaskConfigService` 读取以下变量（括号内为默认值）：

- `HTTP_TASK_MAX_ATTEMPTS` (3)
- `HTTP_TASK_TIMEOUT` (30, 秒)
- `HTTP_TASK_RETRY_DELAY` (1000, 毫秒)
- `HTTP_TASK_RETRY_MULTIPLIER` (2.0)
- `HTTP_TASK_TRANSPORT` ('async')
- `HTTP_TASK_RATE_LIMITER_ENABLED` (true)
- `HTTP_TASK_RATE_LIMIT` (10) 仅作默认值保留；具体限流依赖 RateLimiter 配置

## RateLimiter 集成（可选）

执行器 `HttpRequestExecutor` 支持注入 `RateLimiterFactory`，当任务包含 `rate_limit_key` 或 `rate_limit_per_second` 时触发限流检查：

1) 配置全局限流器（示例）

```yaml
# config/packages/framework.yaml
framework:
  rate_limiter:
    generic:
      policy: 'token_bucket'
      limit: 10
      rate: { interval: '1 second', amount: 10 }
```

2) 通过服务调用为执行器注入工厂（示例：使用命名工厂 `limiter.generic`）

```yaml
# config/services.yaml
services:
  Tourze\HttpRequestTaskBundle\Service\HttpRequestExecutor:
    calls:
      - [ setRateLimiterFactory, [ '@limiter.generic' ] ]
```

> 若未注入工厂或禁用 `HTTP_TASK_RATE_LIMITER_ENABLED=false`，则不生效。

## EasyAdmin 集成

Bundle 已提供两个 CRUD 控制器（通过 Attribute 注册路由）：

- `HttpRequestTaskCrudController` 路径：`/http-request-task`
- `HttpRequestLogCrudController` 路径：`/http-request-log`

在你的 EasyAdmin Dashboard 中添加菜单项（示例）：

```php
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestLog;

public function configureMenuItems(): iterable
{
    yield MenuItem::linkToCrud('HTTP Tasks', 'fa fa-paper-plane', HttpRequestTask::class);
    yield MenuItem::linkToCrud('HTTP Logs', 'fa fa-clipboard-list', HttpRequestLog::class);
}
```

任务列表支持内联操作：失败任务“重试”、待处理/失败任务“取消”。详情页可查看完整请求/响应数据与错误信息。

## 实体字段速览

`HttpRequestTask`

- 标识与状态：`id`、`uuid`、`status(pending|processing|completed|failed|cancelled)`
- 请求：`method`、`url`、`headers(json)`、`body(text)`、`contentType`
- 重试与限时：`maxAttempts`、`attempts`、`timeout`、`retryDelay(ms)`、`retryMultiplier`
- 调度与审计：`scheduledTime`、`startedTime`、`completedTime`、`lastAttemptTime`、`createdTime`、`updatedTime`
- 最近响应与错误：`lastResponseCode`、`lastResponseBody`、`lastErrorMessage`
- 限流与扩展：`rateLimitKey`、`rateLimitPerSecond`、`metadata`

`HttpRequestLog`

- 关联：`task`、`attemptNumber`、`executedTime`、`createdTime`
- 请求与响应：`requestHeaders`、`requestBody`、`responseCode`、`responseHeaders`、`responseBody(自动截断 10k)`、`responseTime(ms)`
- 结果：`result(success|failure|timeout|network_error)`、`errorMessage`

## 测试与质量

```bash
# 仅本包测试
./vendor/bin/phpunit packages/http-request-task-bundle

# 全仓测试覆盖率
./vendor/bin/phpunit packages/http-request-task-bundle --coverage-html coverage/

# 静态分析（仓库 CI 示例）
./vendor/bin/phpstan analyse packages/http-request-task-bundle/src -l 1
```

## 许可

MIT

—— 若在接入中发现不一致或改进点，欢迎提 Issue/PR（例如上文提到的 `scheduled_time/scheduled_at` 字段命名一致性）。
