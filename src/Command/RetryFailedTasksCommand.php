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
use Tourze\HttpRequestTaskBundle\Service;
use Tourze\HttpRequestTaskBundle\Service\HttpRequestTaskService;

#[AsCommand(
    name: self::NAME,
    description: 'Retry failed HTTP request tasks'
)]
class RetryFailedTasksCommand extends Command
{
    public const NAME = 'http-request-task:retry-failed';

    public function __construct(
        private readonly HttpRequestTaskService $taskService,
        private readonly Service\CommandInputValidator $inputValidator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('task-id', InputArgument::OPTIONAL, 'Specific task ID to retry')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of tasks to retry', '100')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force retry even if max attempts reached')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be retried without actually retrying')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $taskIdRaw = $input->getArgument('task-id');
        $limit = $this->inputValidator->getIntOption($input, 'limit', 100);
        $force = $this->inputValidator->getBoolOption($input, 'force');
        $dryRun = $this->inputValidator->getBoolOption($input, 'dry-run');

        if ($dryRun) {
            $io->note('Dry run mode - no tasks will be actually retried');
        }

        if (null !== $taskIdRaw) {
            $taskId = is_numeric($taskIdRaw) ? (int) $taskIdRaw : 0;
            if ($taskId <= 0) {
                $io->error('Invalid task ID provided');

                return Command::FAILURE;
            }

            return $this->retrySingleTask($io, $taskId, $force, $dryRun);
        }

        return $this->retryMultipleTasks($io, $limit, $force, $dryRun);
    }

    private function retrySingleTask(SymfonyStyle $io, int $taskId, bool $force, bool $dryRun): int
    {
        $task = $this->taskService->findTaskById($taskId);

        if (null === $task) {
            $io->error(sprintf('Task with ID %d not found', $taskId));

            return Command::FAILURE;
        }

        if (HttpRequestTask::STATUS_FAILED !== $task->getStatus()) {
            $io->warning(sprintf('Task %d is not in failed status (current: %s)', $taskId, $task->getStatus()));

            return Command::FAILURE;
        }

        if (!$task->canRetry() && !$force) {
            $io->error(sprintf('Task %d has exceeded maximum retry attempts. Use --force to override.', $taskId));

            return Command::FAILURE;
        }

        if ($dryRun) {
            $io->success(sprintf('Would retry task %d (URL: %s)', $taskId, $task->getUrl()));

            return Command::SUCCESS;
        }

        if ($force && !$task->canRetry()) {
            $task->setMaxAttempts($task->getAttempts() + 1);
        }

        try {
            $this->taskService->retryTask($task);
            $io->success(sprintf('Task %d has been queued for retry', $taskId));

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error(sprintf('Failed to retry task %d: %s', $taskId, $e->getMessage()));

            return Command::FAILURE;
        }
    }

    private function retryMultipleTasks(SymfonyStyle $io, int $limit, bool $force, bool $dryRun): int
    {
        $failedTasks = $this->taskService->findFailedTasks($limit);

        if (0 === count($failedTasks)) {
            $io->info('No failed tasks found');

            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d failed tasks', count($failedTasks)));

        if ($dryRun) {
            return $this->showDryRunPreview($io, $failedTasks, $force);
        }

        return $this->processTaskRetries($io, $failedTasks, $force);
    }

    /**
     * @param HttpRequestTask[] $failedTasks
     */
    private function showDryRunPreview(SymfonyStyle $io, array $failedTasks, bool $force): int
    {
        $table = $io->createTable();
        $table->setHeaders(['ID', 'URL', 'Method', 'Attempts', 'Can Retry']);

        foreach ($failedTasks as $task) {
            $table->addRow([
                $task->getId(),
                substr($task->getUrl(), 0, 50),
                $task->getMethod(),
                sprintf('%d/%d', $task->getAttempts(), $task->getMaxAttempts()),
                $this->canTaskBeRetried($task, $force) ? 'Yes' : 'No',
            ]);
        }

        $table->render();
        $io->success(sprintf('Would retry %d tasks', count($failedTasks)));

        return Command::SUCCESS;
    }

    /**
     * @param HttpRequestTask[] $failedTasks
     */
    private function processTaskRetries(SymfonyStyle $io, array $failedTasks, bool $force): int
    {
        $retriedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        $progressBar = $io->createProgressBar(count($failedTasks));
        $progressBar->start();

        foreach ($failedTasks as $task) {
            $progressBar->advance();
            $result = $this->processTaskRetry($task, $force);
            if ('retried' === $result) {
                ++$retriedCount;
            } elseif ('skipped' === $result) {
                ++$skippedCount;
            } else {
                ++$errorCount;
            }
        }

        $progressBar->finish();
        $io->newLine(2);

        $io->success(sprintf(
            'Retry complete: %d retried, %d skipped, %d errors',
            $retriedCount,
            $skippedCount,
            $errorCount
        ));

        return Command::SUCCESS;
    }

    /**
     * @return 'retried'|'skipped'|'error'
     */
    private function processTaskRetry(HttpRequestTask $task, bool $force): string
    {
        if (!$this->canTaskBeRetried($task, $force)) {
            return 'skipped';
        }

        if ($force && !$task->canRetry()) {
            $task->setMaxAttempts($task->getAttempts() + 1);
        }

        try {
            $this->taskService->retryTask($task);

            return 'retried';
        } catch (\Exception $e) {
            return 'error';
        }
    }

    private function canTaskBeRetried(HttpRequestTask $task, bool $force): bool
    {
        return $task->canRetry() || $force;
    }
}
