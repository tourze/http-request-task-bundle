<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;
use Tourze\HttpRequestTaskBundle\Exception\InvalidTaskConfigException;
use Tourze\HttpRequestTaskBundle\Service\BatchTaskService;

#[AsCommand(
    name: self::NAME,
    description: 'Create batch HTTP request tasks'
)]
class CreateBatchTasksCommand extends Command
{
    public const NAME = 'http-request-task:create-batch';

    public function __construct(
        private readonly BatchTaskService $batchTaskService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('type', InputArgument::REQUIRED, 'Type of batch (urls, api, webhook, scheduled)')
            ->addArgument('source', InputArgument::REQUIRED, 'Source file (JSON) or base URL')
            ->addOption('method', 'm', InputOption::VALUE_REQUIRED, 'HTTP method', 'GET')
            ->addOption('priority', 'p', InputOption::VALUE_REQUIRED, 'Priority (high, normal, low)', 'normal')
            ->addOption('timeout', 't', InputOption::VALUE_REQUIRED, 'Timeout in seconds', '30')
            ->addOption('max-attempts', 'a', InputOption::VALUE_REQUIRED, 'Max retry attempts', '3')
            ->addOption('scheduled-time', 's', InputOption::VALUE_REQUIRED, 'Schedule time (for scheduled type)')
            ->addOption('interval', 'i', InputOption::VALUE_REQUIRED, 'Interval in seconds (for scheduled type)', '60')
            ->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'Number of tasks (for scheduled type)', '10')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be created without creating')
        ;
    }

    private function validateStringArgument(mixed $value, string $name, SymfonyStyle $io): ?string
    {
        if (!is_string($value)) {
            $io->error("{$name} argument must be a string");

            return null;
        }

        return $value;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $type = $this->validateStringArgument($input->getArgument('type'), 'Type', $io);
        if (null === $type) {
            return Command::FAILURE;
        }

        $source = $this->validateStringArgument($input->getArgument('source'), 'Source', $io);
        if (null === $source) {
            return Command::FAILURE;
        }

        $commonOptions = $this->buildCommonOptions($input);

        if ($dryRun) {
            $io->note('DRY RUN MODE - No tasks will be created');
        }

        try {
            /** @var array<array{
             *     url: string,
             *     method?: string,
             *     headers?: array<string, string>,
             *     body?: string|null,
             *     contentType?: string|null,
             *     priority?: int,
             *     options?: array<string, mixed>
             * }> $tasks */
            $tasks = match ($type) {
                'urls' => $this->createFromUrls($source, $commonOptions),
                'api' => $this->createApiCalls($source, $commonOptions),
                'webhook' => $this->createWebhookEvents($source, $commonOptions),
                'scheduled' => $this->createScheduledTasks($source, $input, $commonOptions),
                default => throw new InvalidTaskConfigException("Unknown batch type: {$type}"),
            };

            if ($dryRun) {
                $this->displayDryRunResults($io, $tasks);
            } else {
                $createdTasks = $this->batchTaskService->createBatch($tasks);
                $io->success(sprintf('Created %d tasks successfully', count($createdTasks)));
                $this->displayCreatedTasks($io, $createdTasks);
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    private function resolvePriority(mixed $priority): int
    {
        $priorityMap = [
            'high' => HttpRequestTask::PRIORITY_HIGH,
            'normal' => HttpRequestTask::PRIORITY_NORMAL,
            'low' => HttpRequestTask::PRIORITY_LOW,
        ];

        return is_string($priority) && isset($priorityMap[$priority])
            ? $priorityMap[$priority]
            : HttpRequestTask::PRIORITY_NORMAL;
    }

    private function resolveIntOption(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCommonOptions(InputInterface $input): array
    {
        return [
            'method' => $input->getOption('method'),
            'priority' => $this->resolvePriority($input->getOption('priority')),
            'options' => [
                'timeout' => $this->resolveIntOption($input->getOption('timeout'), 30),
                'max_attempts' => $this->resolveIntOption($input->getOption('max-attempts'), 3),
            ],
        ];
    }

    /**
     * @return mixed
     */
    private function loadJsonFile(string $source): mixed
    {
        if (!file_exists($source)) {
            throw new InvalidTaskConfigException("File not found: {$source}");
        }

        $content = file_get_contents($source);
        if (false === $content) {
            throw new InvalidTaskConfigException("Could not read file: {$source}");
        }
        $data = json_decode($content, true);
        if (null === $data) {
            throw new InvalidTaskConfigException("Invalid JSON format in file: {$source}");
        }

        return $data;
    }

    /**
     * @param mixed $data
     *
     * @return array<string>
     */
    private function extractUrlsFromData(mixed $data): array
    {
        if (!is_array($data) || !isset($data['urls']) || !is_array($data['urls'])) {
            throw new InvalidTaskConfigException('Invalid JSON format. Expected {"urls": [...]}');
        }

        $urls = [];
        foreach ($data['urls'] as $url) {
            if (!is_string($url)) {
                throw new InvalidTaskConfigException('Each URL must be a string');
            }
            $urls[] = $url;
        }

        return $urls;
    }

    /**
     * @param array<string, mixed> $commonOptions
     *
     * @return array<array<string, mixed>>
     */
    private function createFromUrls(string $source, array $commonOptions): array
    {
        $data = $this->loadJsonFile($source);
        $urls = $this->extractUrlsFromData($data);

        $tasks = [];
        foreach ($urls as $url) {
            $tasks[] = array_merge(['url' => $url], $commonOptions);
        }

        return $tasks;
    }

    /**
     * @param mixed $data
     *
     * @return array<mixed>
     */
    private function extractEndpointsFromData(mixed $data): array
    {
        if (!is_array($data) || !isset($data['endpoints']) || !is_array($data['endpoints'])) {
            throw new InvalidTaskConfigException('Invalid JSON format. Expected {"endpoints": [...]}');
        }

        return $data['endpoints'];
    }

    /**
     * @param array<string, mixed> $commonOptions
     *
     * @return array<array<string, mixed>>
     */
    private function createApiCalls(string $source, array $commonOptions): array
    {
        $data = $this->loadJsonFile($source);
        $endpoints = $this->extractEndpointsFromData($data);

        $tasks = [];
        foreach ($endpoints as $endpoint) {
            $tasks[] = $this->buildApiCallTask($endpoint, $commonOptions);
        }

        return $tasks;
    }

    /**
     * @param mixed                $endpoint
     * @param array<string, mixed> $commonOptions
     *
     * @return array<string, mixed>
     */
    private function buildApiCallTask(mixed $endpoint, array $commonOptions): array
    {
        if (!is_array($endpoint)) {
            throw new InvalidTaskConfigException('Each endpoint must be an array');
        }
        if (!isset($endpoint['url']) || !is_string($endpoint['url'])) {
            throw new InvalidTaskConfigException('Each endpoint must have a valid URL');
        }

        $method = isset($endpoint['method']) && is_string($endpoint['method'])
            ? $endpoint['method']
            : 'POST';
        $data = isset($endpoint['data']) && is_array($endpoint['data'])
            ? $endpoint['data']
            : [];

        return array_merge([
            'url' => $endpoint['url'],
            'method' => $method,
            'body' => json_encode($data),
            'contentType' => 'application/json',
        ], $commonOptions);
    }

    /**
     * @param array<string, mixed> $commonOptions
     *
     * @return array<array<string, mixed>>
     */
    private function createWebhookEvents(string $source, array $commonOptions): array
    {
        $data = $this->loadJsonFile($source);

        if (!is_array($data)
            || !isset($data['webhook_url']) || !is_string($data['webhook_url'])
            || !isset($data['events']) || !is_array($data['events'])) {
            throw new InvalidTaskConfigException('Invalid JSON format. Expected {"webhook_url": "...", "events": [...]}');
        }

        $webhookUrl = $data['webhook_url'];
        $tasks = [];
        foreach ($data['events'] as $event) {
            $tasks[] = $this->buildWebhookEventTask($webhookUrl, $event, $commonOptions);
        }

        return $tasks;
    }

    /**
     * @param mixed                $event
     * @param array<string, mixed> $commonOptions
     *
     * @return array<string, mixed>
     */
    private function buildWebhookEventTask(string $webhookUrl, mixed $event, array $commonOptions): array
    {
        if (!is_array($event)) {
            throw new InvalidTaskConfigException('Each event must be an array');
        }

        $eventType = isset($event['type']) && is_string($event['type'])
            ? $event['type']
            : 'unknown';
        $eventId = isset($event['id']) && is_string($event['id'])
            ? $event['id']
            : uniqid();

        return array_merge([
            'url' => $webhookUrl,
            'method' => 'POST',
            'body' => json_encode($event),
            'contentType' => 'application/json',
            'headers' => [
                'X-Event-Type' => $eventType,
                'X-Event-Id' => $eventId,
            ],
        ], $commonOptions);
    }

    private function parseScheduledTime(mixed $scheduledTime): \DateTimeImmutable
    {
        if (null !== $scheduledTime && !is_string($scheduledTime)) {
            throw new InvalidTaskConfigException('Scheduled time must be a string');
        }

        return null !== $scheduledTime
            ? new \DateTimeImmutable($scheduledTime)
            : new \DateTimeImmutable('+1 minute');
    }

    /**
     * @param array<string, mixed> $commonOptions
     *
     * @return array<string, mixed>
     */
    private function buildScheduledTask(string $url, \DateTimeImmutable $scheduledAt, array $commonOptions): array
    {
        $taskData = array_merge(['url' => $url], $commonOptions);
        if (!isset($taskData['options']) || !is_array($taskData['options'])) {
            $taskData['options'] = [];
        }
        $taskData['options']['scheduled_at'] = $scheduledAt;

        return $taskData;
    }

    /**
     * @param array<string, mixed> $commonOptions
     *
     * @return array<array<string, mixed>>
     */
    private function createScheduledTasks(
        string $url,
        InputInterface $input,
        array $commonOptions,
    ): array {
        $startTime = $this->parseScheduledTime($input->getOption('scheduled-time'));
        $interval = $this->resolveIntOption($input->getOption('interval'), 60);
        $count = $this->resolveIntOption($input->getOption('count'), 10);

        $tasks = [];
        $currentTime = clone $startTime;

        for ($i = 0; $i < $count; ++$i) {
            $tasks[] = $this->buildScheduledTask($url, clone $currentTime, $commonOptions);
            $currentTime = $currentTime->modify("+{$interval} seconds");
        }

        return $tasks;
    }

    /**
     * @param array<array<string, mixed>> $tasks
     */
    private function displayDryRunResults(SymfonyStyle $io, array $tasks): void
    {
        $io->title('Tasks to be created');
        $table = $io->createTable();
        $table->setHeaders(['#', 'URL', 'Method', 'Priority', 'Scheduled']);

        foreach ($tasks as $index => $task) {
            $table->addRow($this->buildTaskDisplayRow($index, $task));
        }

        $table->render();
        $io->info(sprintf('Would create %d tasks', count($tasks)));
    }

    /**
     * @param array<string, mixed> $task
     */
    private function extractTaskUrl(array $task): string
    {
        return isset($task['url']) && is_string($task['url']) ? $task['url'] : '';
    }

    /**
     * @param array<string, mixed> $task
     */
    private function extractTaskMethod(array $task): string
    {
        return isset($task['method']) && is_string($task['method']) ? $task['method'] : 'GET';
    }

    /**
     * @param array<string, mixed> $task
     */
    private function extractTaskPriority(array $task): int
    {
        return isset($task['priority']) && is_int($task['priority'])
            ? $task['priority']
            : HttpRequestTask::PRIORITY_NORMAL;
    }

    /**
     * @param array<string, mixed> $task
     *
     * @return array{int, string, string, string, string}
     */
    private function buildTaskDisplayRow(int $index, array $task): array
    {
        return [
            $index + 1,
            substr($this->extractTaskUrl($task), 0, 50),
            $this->extractTaskMethod($task),
            $this->getPriorityName($this->extractTaskPriority($task)),
            $this->extractScheduledTime($task),
        ];
    }

    /**
     * @param array<string, mixed> $task
     */
    private function extractScheduledTime(array $task): string
    {
        $options = $task['options'] ?? null;
        if (!is_array($options)) {
            return 'Immediate';
        }

        $scheduledAt = $options['scheduled_at'] ?? null;

        return $scheduledAt instanceof \DateTimeImmutable
            ? $scheduledAt->format('Y-m-d H:i:s')
            : 'Immediate';
    }

    /**
     * @param HttpRequestTask[] $tasks
     */
    private function renderTasksTable(SymfonyStyle $io, array $tasks): void
    {
        $table = $io->createTable();
        $table->setHeaders(['ID', 'UUID', 'URL', 'Status']);

        foreach ($tasks as $task) {
            $table->addRow([
                $task->getId(),
                substr($task->getUuid(), 0, 8) . '...',
                substr($task->getUrl(), 0, 50),
                $task->getStatus(),
            ]);
        }

        $table->render();
    }

    /**
     * @param HttpRequestTask[] $tasks
     */
    private function displayCreatedTasks(SymfonyStyle $io, array $tasks): void
    {
        if (count($tasks) <= 10) {
            $this->renderTasksTable($io, $tasks);
        } else {
            $io->info(sprintf('Created %d tasks. First task ID: %d', count($tasks), $tasks[0]->getId()));
        }
    }

    private function getPriorityName(int $priority): string
    {
        return match ($priority) {
            HttpRequestTask::PRIORITY_HIGH => 'High',
            HttpRequestTask::PRIORITY_NORMAL => 'Normal',
            HttpRequestTask::PRIORITY_LOW => 'Low',
            default => 'Unknown',
        };
    }
}
