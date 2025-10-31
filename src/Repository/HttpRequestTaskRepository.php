<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;
use Tourze\PHPUnitSymfonyKernelTest\Attribute\AsRepository;

/**
 * @extends ServiceEntityRepository<HttpRequestTask>
 */
#[AsRepository(entityClass: HttpRequestTask::class)]
class HttpRequestTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HttpRequestTask::class);
    }

    public function save(HttpRequestTask $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(HttpRequestTask $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return HttpRequestTask[]
     */
    public function findPendingTasks(int $limit = 100): array
    {
        /** @phpstan-ignore return.type */
        return $this->createQueryBuilder('t')
            ->where('t.status = :status')
            ->andWhere('t.scheduledTime IS NULL OR t.scheduledTime <= :now')
            ->setParameter('status', HttpRequestTask::STATUS_PENDING)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('t.priority', 'DESC')
            ->addOrderBy('t.createdTime', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * @return HttpRequestTask[]
     */
    public function findFailedTasks(int $limit = 100): array
    {
        /** @phpstan-ignore return.type */
        return $this->createQueryBuilder('t')
            ->where('t.status = :status')
            ->setParameter('status', HttpRequestTask::STATUS_FAILED)
            ->orderBy('t.createdTime', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * @return HttpRequestTask[]
     */
    public function findTasksByStatus(string $status, int $limit = 100): array
    {
        /** @phpstan-ignore return.type */
        return $this->createQueryBuilder('t')
            ->where('t.status = :status')
            ->setParameter('status', $status)
            ->orderBy('t.createdTime', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult()
        ;
    }

    public function findByUuid(string $uuid): ?HttpRequestTask
    {
        /** @phpstan-ignore return.type */
        return $this->createQueryBuilder('t')
            ->where('t.uuid = :uuid')
            ->setParameter('uuid', $uuid)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }

    public function countByStatus(string $status): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    /**
     * @return HttpRequestTask[]
     */
    public function findExpiredTasks(\DateTimeImmutable $before, int $limit = 100): array
    {
        /** @phpstan-ignore return.type */
        return $this->createQueryBuilder('t')
            ->where('t.status IN (:statuses)')
            ->andWhere('t.createdTime < :before')
            ->setParameter('statuses', [
                HttpRequestTask::STATUS_COMPLETED,
                HttpRequestTask::STATUS_FAILED,
                HttpRequestTask::STATUS_CANCELLED,
            ])
            ->setParameter('before', $before)
            ->orderBy('t.createdTime', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * @return HttpRequestTask[]
     */
    public function findScheduledTasks(?\DateTimeImmutable $before = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.status = :status')
            ->andWhere('t.scheduledTime IS NOT NULL')
            ->setParameter('status', HttpRequestTask::STATUS_PENDING)
            ->orderBy('t.scheduledTime', 'ASC')
        ;

        if (null !== $before) {
            $qb->andWhere('t.scheduledTime <= :before')
                ->setParameter('before', $before)
            ;
        }

        /** @phpstan-ignore return.type */
        return $qb->getQuery()->getResult();
    }

    /**
     * @return HttpRequestTask[]
     */
    public function findRetriableTasks(int $limit = 100): array
    {
        /** @phpstan-ignore return.type */
        return $this->createQueryBuilder('t')
            ->where('t.status = :status')
            ->andWhere('t.attempts < t.maxAttempts')
            ->setParameter('status', HttpRequestTask::STATUS_FAILED)
            ->orderBy('t.priority', 'DESC')
            ->addOrderBy('t.lastAttemptTime', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * @return HttpRequestTask[]
     */
    public function findByUrl(string $url, int $limit = 100): array
    {
        /** @phpstan-ignore return.type */
        return $this->createQueryBuilder('t')
            ->where('t.url LIKE :url')
            ->setParameter('url', '%' . $url . '%')
            ->orderBy('t.createdTime', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * @return array<string, int>
     */
    public function getStatusStatistics(): array
    {
        /** @var array<array{status: string, count: string}> $results */
        $results = $this->createQueryBuilder('t')
            ->select('t.status', 'COUNT(t.id) as count')
            ->groupBy('t.status')
            ->getQuery()
            ->getResult()
        ;

        $statistics = [];
        foreach ($results as $row) {
            $statistics[$row['status']] = (int) $row['count'];
        }

        return $statistics;
    }

    /**
     * @return array<int, int>
     */
    public function getPriorityDistribution(): array
    {
        /** @var array<array{priority: int, count: string}> $results */
        $results = $this->createQueryBuilder('t')
            ->select('t.priority', 'COUNT(t.id) as count')
            ->groupBy('t.priority')
            ->orderBy('t.priority', 'DESC')
            ->getQuery()
            ->getResult()
        ;

        $distribution = [];
        foreach ($results as $row) {
            $distribution[(int) $row['priority']] = (int) $row['count'];
        }

        return $distribution;
    }

    /**
     * @return HttpRequestTask[]
     */
    public function findByPriority(int $priority, int $limit = 100): array
    {
        /** @phpstan-ignore return.type */
        return $this->createQueryBuilder('t')
            ->where('t.priority = :priority')
            ->setParameter('priority', $priority)
            ->orderBy('t.createdTime', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * @return HttpRequestTask[]
     */
    public function findProcessingTasks(int $limit = 100): array
    {
        /** @phpstan-ignore return.type */
        return $this->createQueryBuilder('t')
            ->where('t.status = :status')
            ->setParameter('status', HttpRequestTask::STATUS_PROCESSING)
            ->orderBy('t.startedTime', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult()
        ;
    }

    public function deleteOldTasks(\DateTimeInterface $before): int
    {
        $qb = $this->createQueryBuilder('t')
            ->delete()
            ->where('t.status IN (:statuses)')
            ->andWhere('t.createdTime < :before')
            ->setParameter('statuses', [
                HttpRequestTask::STATUS_COMPLETED,
                HttpRequestTask::STATUS_FAILED,
                HttpRequestTask::STATUS_CANCELLED,
            ])
            ->setParameter('before', $before)
        ;

        $result = $qb->getQuery()->execute();

        return is_int($result) ? $result : 0;
    }
}
