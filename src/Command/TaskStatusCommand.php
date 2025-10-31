<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;
use Tourze\HttpRequestTaskBundle\Repository\HttpRequestLogRepository;
use Tourze\HttpRequestTaskBundle\Repository\HttpRequestTaskRepository;
use Tourze\HttpRequestTaskBundle\Service;
use Tourze\HttpRequestTaskBundle\Service\HttpRequestTaskService;

#[AsCommand(
    name: self::NAME,
    description: 'Display HTTP request tasks status'
)]
class TaskStatusCommand extends Command
{
    public const NAME = 'http-request-task:status';

    public function __construct(
        private readonly HttpRequestTaskService $taskService,
        private readonly HttpRequestTaskRepository $taskRepository,
        private readonly HttpRequestLogRepository $logRepository,
        private readonly Service\CommandInputValidator $inputValidator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('status', 's', InputOption::VALUE_REQUIRED, 'Filter by status')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Limit number of tasks to display', '20')
            ->addOption('statistics', null, InputOption::VALUE_NONE, 'Show only statistics')
            ->addOption('detailed', 'd', InputOption::VALUE_NONE, 'Show detailed statistics')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->inputValidator->getBoolOption($input, 'statistics')) {
            $detailed = $this->inputValidator->getBoolOption($input, 'detailed');

            return $this->showStatistics($io, $detailed);
        }

        $status = $input->getOption('status');
        $limit = $this->inputValidator->getIntOption($input, 'limit', 20);

        if (is_string($status) && '' !== $status) {
            return $this->showTasksByStatus($io, $status, $limit);
        }

        return $this->showAllTasks($io, $limit);
    }

    private function showStatistics(SymfonyStyle $io, bool $detailed = false): int
    {
        $stats = $this->taskService->getTaskStatistics();

        $io->title('HTTP Request Task Statistics');

        $table = new Table($io);
        $table->setHeaders(['Status', 'Count']);

        foreach ($stats as $status => $count) {
            assert(is_string($status));
            assert(is_int($count));
            $table->addRow([ucfirst($status), (string) $count]);
        }

        $total = array_sum($stats);
        $table->addRow(['<info>Total</info>', "<info>{$total}</info>"]);

        $table->render();

        if ($detailed) {
            $this->showDetailedStatistics($io);
        }

        return Command::SUCCESS;
    }

    private function showTasksByStatus(SymfonyStyle $io, string $status, int $limit): int
    {
        $tasks = $this->taskService->findTasksByStatus($status, $limit);

        if (0 === count($tasks)) {
            $io->info(sprintf('No tasks found with status "%s"', $status));

            return Command::SUCCESS;
        }

        $this->displayTasks($io, $tasks, sprintf('Tasks with status "%s"', $status));

        return Command::SUCCESS;
    }

    private function showAllTasks(SymfonyStyle $io, int $limit): int
    {
        $pendingTasks = $this->taskService->findPendingTasks($limit);

        if (count($pendingTasks) > 0) {
            $this->displayTasks($io, $pendingTasks, 'Pending Tasks');
        }

        $failedTasks = $this->taskService->findFailedTasks($limit);

        if (count($failedTasks) > 0) {
            $this->displayTasks($io, $failedTasks, 'Failed Tasks');
        }

        if (0 === count($pendingTasks) && 0 === count($failedTasks)) {
            $io->info('No pending or failed tasks found');
        }

        return Command::SUCCESS;
    }

    /**
     * @param HttpRequestTask[] $tasks
     */
    private function displayTasks(SymfonyStyle $io, array $tasks, string $title): void
    {
        $io->section($title);

        $table = new Table($io);
        $table->setHeaders(['ID', 'UUID', 'Method', 'URL', 'Status', 'Attempts', 'Created']);

        foreach ($tasks as $task) {
            $url = $task->getUrl();
            if (strlen($url) > 50) {
                $url = substr($url, 0, 47) . '...';
            }

            $status = $task->getStatus();
            $statusFormatted = match ($status) {
                HttpRequestTask::STATUS_PENDING => "<comment>{$status}</comment>",
                HttpRequestTask::STATUS_PROCESSING => "<info>{$status}</info>",
                HttpRequestTask::STATUS_COMPLETED => "<info>{$status}</info>",
                HttpRequestTask::STATUS_FAILED => "<error>{$status}</error>",
                HttpRequestTask::STATUS_CANCELLED => "<comment>{$status}</comment>",
                default => $status,
            };

            $table->addRow([
                $task->getId(),
                substr($task->getUuid(), 0, 8) . '...',
                $task->getMethod(),
                $url,
                $statusFormatted,
                sprintf('%d/%d', $task->getAttempts(), $task->getMaxAttempts()),
                $task->getCreatedTime()->format('Y-m-d H:i:s'),
            ]);
        }

        $table->render();
    }

    private function showDetailedStatistics(SymfonyStyle $io): void
    {
        $io->section('Priority Distribution');
        $priorityDist = $this->taskRepository->getPriorityDistribution();

        $table = new Table($io);
        $table->setHeaders(['Priority', 'Count']);

        $priorityNames = [
            HttpRequestTask::PRIORITY_HIGH => 'High',
            HttpRequestTask::PRIORITY_NORMAL => 'Normal',
            HttpRequestTask::PRIORITY_LOW => 'Low',
        ];

        foreach ($priorityDist as $priority => $count) {
            assert(is_int($priority));
            assert(is_int($count));
            $priorityName = $priorityNames[$priority] ?? "Unknown ({$priority})";
            $table->addRow([$priorityName, (string) $count]);
        }

        $table->render();

        $io->section('Log Statistics');
        $since = new \DateTimeImmutable('-7 days');
        $logStats = $this->logRepository->getResultStatistics($since);

        $table = new Table($io);
        $table->setHeaders(['Result', 'Count (Last 7 Days)']);

        foreach ($logStats as $result => $count) {
            assert(is_string($result));
            assert(is_int($count));
            $table->addRow([ucfirst($result), (string) $count]);
        }

        $table->render();

        $avgResponseTime = $this->logRepository->getAverageResponseTime($since);
        if (null !== $avgResponseTime) {
            $io->info(sprintf('Average response time (last 7 days): %.2f ms', $avgResponseTime));
        }

        $io->section('Response Code Distribution');
        $responseCodeDist = $this->logRepository->getResponseCodeDistribution($since);

        if (count($responseCodeDist) > 0) {
            $table = new Table($io);
            $table->setHeaders(['Response Code', 'Count']);

            foreach ($responseCodeDist as $code => $count) {
                assert(is_int($code));
                assert(is_int($count));
                $codeFormatted = match (true) {
                    $code >= 200 && $code < 300 => "<info>{$code}</info>",
                    $code >= 400 && $code < 500 => "<comment>{$code}</comment>",
                    $code >= 500 => "<error>{$code}</error>",
                    default => (string) $code,
                };
                $table->addRow([$codeFormatted, (string) $count]);
            }

            $table->render();
        }
    }
}
