# HTTP Request Task Bundle

[English](README.md) | [中文](README.zh-CN.md)

A comprehensive Symfony Bundle for unified creation, scheduling, execution, and monitoring of external HTTP requests (Webhooks/API calls). Features built-in asynchronous processing, retry mechanisms, scheduled execution, rate limiting, execution logging, and EasyAdmin backend integration.

## Features

- **Asynchronous Execution** - Built on Symfony Messenger
- **Retry Strategies** - Configurable retry with exponential backoff
- **Rate Limiting** - Optional integration with Symfony RateLimiter  
- **Priority Queues** - HIGH/NORMAL/LOW priority levels
- **Scheduled Tasks** - Time-based task scheduling
- **Batch Operations** - Bulk task creation utilities
- **Audit Logging** - Complete request/response logging
- **Admin Interface** - EasyAdmin CRUD controllers
- **Monitoring** - Task status and statistics commands

## Installation

```bash
composer require tourze/http-request-task-bundle
```

## Configuration

Add the bundle to `config/bundles.php`:

```php
return [
    // ...
    Tourze\HttpRequestTaskBundle\HttpRequestTaskBundle::class => ['all' => true],
];
```

## Console Commands

### 1. Task Status (http-request-task:status)

View HTTP request task status and statistics:

```bash
# View all task statistics
bin/console http-request-task:status

# Filter by status
bin/console http-request-task:status --status=failed

# Show statistics only
bin/console http-request-task:status --statistics

# Detailed statistics with breakdown
bin/console http-request-task:status --statistics --detailed

# Limit results
bin/console http-request-task:status --limit=50
```

**Options:**
- `--status, -s`: Filter by status (pending/processing/completed/failed/cancelled)
- `--limit, -l`: Limit number of tasks displayed (default: 20)
- `--statistics`: Show statistics only
- `--detailed, -d`: Show detailed statistics

### 2. Retry Failed Tasks (http-request-task:retry-failed)

Retry failed HTTP request tasks:

```bash
# Retry all failed tasks
bin/console http-request-task:retry-failed

# Retry specific task
bin/console http-request-task:retry-failed 123

# Limit retry count
bin/console http-request-task:retry-failed --limit=10

# Force retry even if max attempts exceeded
bin/console http-request-task:retry-failed --force

# Dry run mode
bin/console http-request-task:retry-failed --dry-run
```

**Arguments:**
- `task-id`: Specific task ID to retry (optional)

**Options:**
- `--limit, -l`: Maximum tasks to retry (default: 100)
- `--force, -f`: Force retry even if max attempts exceeded
- `--dry-run`: Show what would be retried without executing

### 3. Cleanup Tasks (http-request-task:cleanup)

Clean up old HTTP request tasks and logs:

```bash
# Clean up tasks older than 90 days (default)
bin/console http-request-task:cleanup

# Clean up tasks older than 30 days
bin/console http-request-task:cleanup --days=30

# Clean logs only
bin/console http-request-task:cleanup --logs-only

# Clean tasks only
bin/console http-request-task:cleanup --tasks-only

# Dry run mode
bin/console http-request-task:cleanup --dry-run

# Custom batch size
bin/console http-request-task:cleanup --batch-size=500
```

**Options:**
- `--days, -d`: Days threshold (default: 90)
- `--dry-run`: Show what would be cleaned without executing
- `--logs-only`: Clean logs only
- `--tasks-only`: Clean tasks only
- `--batch-size, -b`: Batch size (default: 100)

### 4. Create Batch Tasks (http-request-task:create-batch)

Create batch HTTP request tasks:

```bash
# Create from URL list
bin/console http-request-task:create-batch urls urls.json

# Create from API endpoints
bin/console http-request-task:create-batch api endpoints.json

# Create webhook events
bin/console http-request-task:create-batch webhook webhook-config.json

# Create scheduled tasks
bin/console http-request-task:create-batch scheduled https://api.example.com/cron \
    --count=10 --interval=60 --scheduled-at="2024-01-01 10:00:00"

# Custom options
bin/console http-request-task:create-batch urls urls.json \
    --method=POST \
    --priority=high \
    --timeout=60 \
    --max-attempts=5

# Dry run mode
bin/console http-request-task:create-batch urls urls.json --dry-run
```

**Arguments:**
- `type`: Batch type (urls/api/webhook/scheduled)
- `source`: Source JSON file or base URL

**Options:**
- `--method, -m`: HTTP method (default: GET)
- `--priority, -p`: Priority (high/normal/low, default: normal)
- `--timeout, -t`: Timeout in seconds (default: 30)
- `--max-attempts, -a`: Maximum retry attempts (default: 3)
- `--scheduled-at, -s`: Schedule time (for scheduled type)
- `--interval, -i`: Interval in seconds (for scheduled type, default: 60)
- `--count, -c`: Number of tasks (for scheduled type, default: 10)
- `--dry-run`: Preview mode without creating tasks

#### Configuration File Examples

**urls.json** (URL list):
```json
{
    "urls": [
        "https://api.example.com/endpoint1",
        "https://api.example.com/endpoint2",
        "https://api.example.com/endpoint3"
    ]
}
```

**endpoints.json** (API endpoints):
```json
{
    "endpoints": [
        {
            "url": "https://api.example.com/users",
            "method": "POST",
            "data": {
                "name": "John Doe",
                "email": "john@example.com"
            }
        },
        {
            "url": "https://api.example.com/orders",
            "method": "PUT",
            "data": {
                "status": "completed"
            }
        }
    ]
}
```

**webhook-config.json** (Webhook configuration):
```json
{
    "webhook_url": "https://webhook.example.com/events",
    "events": [
        {
            "type": "user.created",
            "id": "evt_123",
            "data": {
                "user_id": "usr_456",
                "email": "user@example.com"
            }
        },
        {
            "type": "order.completed",
            "id": "evt_789",
            "data": {
                "order_id": "ord_101",
                "total": 99.99
            }
        }
    ]
}
```

## Usage Examples

### Basic Usage

```php
use Tourze\HttpRequestTaskBundle\Service\HttpRequestTaskService;

class MyService
{
    public function __construct(
        private HttpRequestTaskService $taskService
    ) {}

    public function createTask(): void
    {
        // Simple GET request
        $task = $this->taskService->createTask('https://api.example.com/data');
        
        // POST request with custom options
        $task = $this->taskService->createTask(
            url: 'https://api.example.com/webhook',
            method: 'POST',
            headers: ['Content-Type' => 'application/json'],
            body: json_encode(['key' => 'value']),
            priority: HttpRequestTask::PRIORITY_HIGH,
            options: [
                'max_attempts' => 5,
                'timeout' => 60,
                'scheduled_at' => new \DateTimeImmutable('+1 hour'),
            ]
        );
    }
}
```

### Batch Operations

```php
use Tourze\HttpRequestTaskBundle\Service\BatchTaskService;

class BatchService
{
    public function __construct(
        private BatchTaskService $batchService
    ) {}

    public function createBatchTasks(): void
    {
        // Create from URL list
        $tasks = $this->batchService->createFromUrls([
            'https://api1.example.com',
            'https://api2.example.com',
            'https://api3.example.com',
        ]);
        
        // Create scheduled batch
        $tasks = $this->batchService->createScheduledBatch(
            startTime: new \DateTimeImmutable('+1 hour'),
            count: 10,
            intervalSeconds: 300,
            taskTemplate: [
                'url' => 'https://api.example.com/cron',
                'method' => 'POST',
            ]
        );
    }
}
```

## Data Model

- `HttpRequestTask`: Core entity representing HTTP request tasks
- `HttpRequestLog`: Execution logs with detailed request/response data
- One-to-many relationship between Task and Logs

## Configuration Options

Environment variables for configuration:

```env
# Default max attempts
HTTP_TASK_MAX_ATTEMPTS=3

# Default timeout in seconds
HTTP_TASK_TIMEOUT=30

# Default retry delay in milliseconds
HTTP_TASK_RETRY_DELAY=1000

# Retry multiplier
HTTP_TASK_RETRY_MULTIPLIER=2.0

# Messenger transport
HTTP_TASK_TRANSPORT=async

# Rate limiter settings
HTTP_TASK_RATE_LIMITER_ENABLED=true

# Default rate limit (requests per second)
HTTP_TASK_RATE_LIMIT=10
```

## Admin Interface

The bundle provides EasyAdmin integration with CRUD controllers for:

- Task management and monitoring
- Execution logs
- Task retry/cancel actions
- Detailed request/response inspection

## Testing

Run the test suite:

```bash
# Run all tests
./vendor/bin/phpunit packages/http-request-task-bundle

# Run specific test suite
./vendor/bin/phpunit packages/http-request-task-bundle/tests/Service/

# Generate coverage report
./vendor/bin/phpunit packages/http-request-task-bundle --coverage-html coverage/
```

## License

MIT License