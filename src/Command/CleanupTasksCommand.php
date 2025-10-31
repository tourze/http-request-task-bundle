<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestLog;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;
use Tourze\HttpRequestTaskBundle\Repository\HttpRequestLogRepository;
use Tourze\HttpRequestTaskBundle\Repository\HttpRequestTaskRepository;

#[AsCommand(
    name: self::NAME,
    description: 'Clean up old HTTP request tasks and logs'
)]
class CleanupTasksCommand extends Command
{
    public const NAME = 'http-request-task:cleanup';

    public function __construct(
        private readonly HttpRequestTaskRepository $taskRepository,
        private readonly HttpRequestLogRepository $logRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Number of days to keep', '90')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be deleted without actually deleting')
            ->addOption('logs-only', null, InputOption::VALUE_NONE, 'Clean up only logs, not tasks')
            ->addOption('tasks-only', null, InputOption::VALUE_NONE, 'Clean up only tasks, not logs')
            ->addOption('batch-size', 'b', InputOption::VALUE_REQUIRED, 'Batch size for deletion', '100')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $daysOption = $input->getOption('days');
        $days = is_numeric($daysOption) ? (int) $daysOption : 90;
        $dryRun = (bool) $input->getOption('dry-run');
        $logsOnly = (bool) $input->getOption('logs-only');
        $tasksOnly = (bool) $input->getOption('tasks-only');
        $batchSizeOption = $input->getOption('batch-size');
        $batchSize = is_numeric($batchSizeOption) ? (int) $batchSizeOption : 100;

        if ($logsOnly && $tasksOnly) {
            $io->error('Cannot use both --logs-only and --tasks-only options');

            return Command::FAILURE;
        }

        $before = new \DateTimeImmutable("-{$days} days");
        $io->title('HTTP Request Task Cleanup');
        $io->info(sprintf('Cleaning up records older than %s', $before->format('Y-m-d H:i:s')));

        if ($dryRun) {
            $io->note('DRY RUN MODE - No data will be deleted');
        }

        $deletedTasks = 0;
        $deletedLogs = 0;

        if (!$logsOnly) {
            $deletedTasks = $this->cleanupTasks($io, $before, $dryRun, $batchSize);
        }

        if (!$tasksOnly) {
            $deletedLogs = $this->cleanupLogs($io, $before, $dryRun, $batchSize);
        }

        $io->success(sprintf(
            'Cleanup complete: %d tasks and %d logs %s',
            $deletedTasks,
            $deletedLogs,
            $dryRun ? 'would be deleted' : 'deleted'
        ));

        return Command::SUCCESS;
    }

    private function cleanupTasks(
        SymfonyStyle $io,
        \DateTimeImmutable $before,
        bool $dryRun,
        int $batchSize,
    ): int {
        return $this->performCleanup(
            $io,
            'tasks',
            'Cleaning up tasks',
            $before,
            $dryRun,
            fn () => $this->taskRepository->findExpiredTasks($before, 1000),
            fn () => $this->taskRepository->deleteOldTasks($before),
            $this->getTaskTableFormatter()
        );
    }

    private function cleanupLogs(
        SymfonyStyle $io,
        \DateTimeImmutable $before,
        bool $dryRun,
        int $batchSize,
    ): int {
        return $this->performCleanup(
            $io,
            'logs',
            'Cleaning up logs',
            $before,
            $dryRun,
            fn () => $this->logRepository->findExpiredLogs($before, 1000),
            fn () => $this->logRepository->deleteOldLogs($before),
            $this->getLogTableFormatter()
        );
    }

    private function performCleanup(
        SymfonyStyle $io,
        string $entityType,
        string $sectionTitle,
        \DateTimeImmutable $before,
        bool $dryRun,
        callable $findExpiredEntities,
        callable $deleteOldEntities,
        callable $tableFormatter,
    ): int {
        $io->section($sectionTitle);

        if ($dryRun) {
            return $this->performDryRun($io, $entityType, $findExpiredEntities, $tableFormatter);
        }

        return $this->performActualDeletion($io, $entityType, $deleteOldEntities);
    }

    private function performDryRun(
        SymfonyStyle $io,
        string $entityType,
        callable $findExpiredEntities,
        callable $tableFormatter,
    ): int {
        $entities = $findExpiredEntities();
        if (!is_array($entities)) {
            $io->error('Invalid response from repository: expected array of entities');

            return 0;
        }

        // Validate that all elements are objects
        $validEntities = [];
        foreach ($entities as $entity) {
            if (is_object($entity)) {
                $validEntities[] = $entity;
            }
        }

        $count = count($validEntities);
        $io->info(sprintf('Found %d %s to delete', $count, $entityType));

        if (0 === $count) {
            return 0;
        }

        $this->renderPreviewTable($io, $validEntities, $tableFormatter, $count);

        return $count;
    }

    private function performActualDeletion(
        SymfonyStyle $io,
        string $entityType,
        callable $deleteOldEntities,
    ): int {
        $progressBar = $io->createProgressBar();
        $progressBar->setMessage("Deleting {$entityType}...");
        $totalDeleted = 0;

        do {
            $result = $deleteOldEntities();
            $deleted = is_int($result) ? $result : 0;
            $totalDeleted += $deleted;

            if ($deleted > 0) {
                $progressBar->advance($deleted);
            }
        } while ($deleted > 0);

        $progressBar->finish();
        $io->newLine(2);

        return $totalDeleted;
    }

    /**
     * @param object[] $entities
     */
    private function renderPreviewTable(
        SymfonyStyle $io,
        array $entities,
        callable $tableFormatter,
        int $totalCount,
    ): void {
        $table = $io->createTable();
        $headersResult = $tableFormatter(null, true); // Get headers
        $headers = is_array($headersResult) ? $headersResult : ['Column'];
        $table->setHeaders($headers);

        $displayed = 0;
        foreach ($entities as $entity) {
            if ($displayed >= 10) {
                break;
            }
            $rowResult = $tableFormatter($entity, false); // Get row data
            $row = is_array($rowResult) ? $rowResult : ['N/A'];
            $table->addRow($row);
            ++$displayed;
        }

        $table->render();

        if ($totalCount > 10) {
            $io->info(sprintf('... and %d more', $totalCount - 10));
        }
    }

    /**
     * @return callable(mixed, bool): array<int, string|int|null>
     */
    private function getTaskTableFormatter(): callable
    {
        return static function (mixed $task, bool $isHeaders): array {
            if ($isHeaders) {
                return ['ID', 'UUID', 'URL', 'Status', 'Created'];
            }

            if (!$task instanceof HttpRequestTask) {
                return ['Invalid', 'Invalid', 'Invalid', 'Invalid', 'Invalid'];
            }

            return [
                $task->getId() ?? 0,
                substr($task->getUuid(), 0, 8) . '...',
                substr($task->getUrl(), 0, 50),
                $task->getStatus(),
                $task->getCreatedTime()->format('Y-m-d H:i:s'),
            ];
        };
    }

    /**
     * @return callable(mixed, bool): array<int, string|int|null>
     */
    private function getLogTableFormatter(): callable
    {
        return static function (mixed $log, bool $isHeaders): array {
            if ($isHeaders) {
                return ['ID', 'Task ID', 'Result', 'Response Code', 'Created'];
            }

            if (!$log instanceof HttpRequestLog) {
                return ['Invalid', 'Invalid', 'Invalid', 'Invalid', 'Invalid'];
            }

            return [
                $log->getId() ?? 0,
                $log->getTask()->getId() ?? 0,
                $log->getResult(),
                $log->getResponseCode() ?? 'N/A',
                $log->getCreatedTime()->format('Y-m-d H:i:s'),
            ];
        };
    }
}
