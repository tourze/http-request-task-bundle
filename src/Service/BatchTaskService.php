<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Service;

use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;
use Tourze\HttpRequestTaskBundle\Exception\InvalidTaskDataException;

readonly class BatchTaskService
{
    public function __construct(
        private HttpRequestTaskService $taskService,
    ) {
    }

    /**
     * @param array<array{
     *     url: string,
     *     method?: string,
     *     headers?: array<string, string>,
     *     body?: string|null,
     *     contentType?: string|null,
     *     priority?: int,
     *     options?: array<string, mixed>
     * }> $tasks
     *
     * @return HttpRequestTask[]
     */
    public function createBatch(array $tasks): array
    {
        $createdTasks = [];

        foreach ($tasks as $taskData) {
            // 确保所有必需的键存在
            if (!isset($taskData['url']) || !is_string($taskData['url'])) {
                throw new InvalidTaskDataException('Task data must contain a valid URL');
            }

            $task = $this->taskService->createTask(
                url: $taskData['url'],
                method: $taskData['method'] ?? HttpRequestTask::METHOD_GET,
                headers: $taskData['headers'] ?? [],
                body: $taskData['body'] ?? null,
                contentType: $taskData['contentType'] ?? null,
                priority: $taskData['priority'] ?? HttpRequestTask::PRIORITY_NORMAL,
                options: $taskData['options'] ?? []
            );

            $createdTasks[] = $task;
        }

        return $createdTasks;
    }

    /**
     * @param string[] $urls
     * @param array<string, mixed> $commonOptions
     *
     * @return HttpRequestTask[]
     */
    public function createFromUrls(array $urls, array $commonOptions = []): array
    {
        /** @var array<array{
         *     url: string,
         *     method?: string,
         *     headers?: array<string, string>,
         *     body?: string|null,
         *     contentType?: string|null,
         *     priority?: int,
         *     options?: array<string, mixed>
         * }> $tasks */
        $tasks = [];

        foreach ($urls as $url) {
            $taskData = array_merge(['url' => $url], $commonOptions);
            $tasks[] = $taskData;
        }

        /** @var array<array{
         *     url: string,
         *     method?: string,
         *     headers?: array<string, string>,
         *     body?: string|null,
         *     contentType?: string|null,
         *     priority?: int,
         *     options?: array<string, mixed>
         * }> $tasks */
        return $this->createBatch($tasks);
    }

    /**
     * @param array<array{url: string, data: array<string, mixed>}> $endpoints
     * @param array<string, mixed> $commonOptions
     *
     * @return HttpRequestTask[]
     */
    public function createApiCalls(array $endpoints, array $commonOptions = []): array
    {
        /** @var array<array{
         *     url: string,
         *     method?: string,
         *     headers?: array<string, string>,
         *     body?: string|null,
         *     contentType?: string|null,
         *     priority?: int,
         *     options?: array<string, mixed>
         * }> $tasks */
        $tasks = [];

        foreach ($endpoints as $endpoint) {
            $taskData = array_merge([
                'url' => $endpoint['url'],
                'method' => HttpRequestTask::METHOD_POST,
                'body' => json_encode($endpoint['data']),
                'contentType' => 'application/json',
            ], $commonOptions);

            $tasks[] = $taskData;
        }

        /** @var array<array{
         *     url: string,
         *     method?: string,
         *     headers?: array<string, string>,
         *     body?: string|null,
         *     contentType?: string|null,
         *     priority?: int,
         *     options?: array<string, mixed>
         * }> $tasks */
        return $this->createBatch($tasks);
    }

    /**
     * @param string $baseUrl
     * @param array<string, array<string, mixed>> $resources
     * @param array<string, mixed> $commonOptions
     *
     * @return HttpRequestTask[]
     */
    public function createResourceFetches(
        string $baseUrl,
        array $resources,
        array $commonOptions = [],
    ): array {
        /** @var array<array{
         *     url: string,
         *     method?: string,
         *     headers?: array<string, string>,
         *     body?: string|null,
         *     contentType?: string|null,
         *     priority?: int,
         *     options?: array<string, mixed>
         * }> $tasks */
        $tasks = [];

        foreach ($resources as $path => $params) {
            $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
            if (count($params) > 0) {
                $url .= '?' . http_build_query($params);
            }

            $taskData = array_merge(['url' => $url], $commonOptions);
            $tasks[] = $taskData;
        }

        /** @var array<array{
         *     url: string,
         *     method?: string,
         *     headers?: array<string, string>,
         *     body?: string|null,
         *     contentType?: string|null,
         *     priority?: int,
         *     options?: array<string, mixed>
         * }> $tasks */
        return $this->createBatch($tasks);
    }

    /**
     * @param string $webhookUrl
     * @param array<array<string, mixed>> $events
     * @param array<string, mixed> $commonOptions
     *
     * @return HttpRequestTask[]
     */
    public function createWebhookEvents(
        string $webhookUrl,
        array $events,
        array $commonOptions = [],
    ): array {
        /** @var array<array{
         *     url: string,
         *     method?: string,
         *     headers?: array<string, string>,
         *     body?: string|null,
         *     contentType?: string|null,
         *     priority?: int,
         *     options?: array<string, mixed>
         * }> $tasks */
        $tasks = [];

        foreach ($events as $event) {
            $taskData = array_merge([
                'url' => $webhookUrl,
                'method' => HttpRequestTask::METHOD_POST,
                'body' => json_encode($event),
                'contentType' => 'application/json',
                'headers' => [
                    'X-Event-Type' => $event['type'] ?? 'unknown',
                    'X-Event-Id' => $event['id'] ?? uniqid(),
                ],
            ], $commonOptions);

            $tasks[] = $taskData;
        }

        /** @var array<array{
         *     url: string,
         *     method?: string,
         *     headers?: array<string, string>,
         *     body?: string|null,
         *     contentType?: string|null,
         *     priority?: int,
         *     options?: array<string, mixed>
         * }> $tasks */
        return $this->createBatch($tasks);
    }

    /**
     * @param \DateTimeInterface $startTime
     * @param int $count
     * @param int $intervalSeconds
     * @param array{
     *     url: string,
     *     method?: string,
     *     headers?: array<string, string>,
     *     body?: string|null,
     *     contentType?: string|null,
     *     priority?: int,
     *     options?: array<string, mixed>
     * } $taskTemplate
     *
     * @return HttpRequestTask[]
     */
    public function createScheduledBatch(
        \DateTimeInterface $startTime,
        int $count,
        int $intervalSeconds,
        array $taskTemplate,
    ): array {
        $tasks = [];
        $currentTime = \DateTimeImmutable::createFromInterface($startTime);

        for ($i = 0; $i < $count; ++$i) {
            $taskData = $taskTemplate;
            $taskData['options'] ??= [];
            $taskData['options']['scheduled_at'] = clone $currentTime;

            $tasks[] = $taskData;

            $currentTime = $currentTime->modify("+{$intervalSeconds} seconds");
        }

        return $this->createBatch($tasks);
    }
}
