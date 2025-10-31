<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestLog;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;
use Tourze\PHPUnitSymfonyKernelTest\Attribute\AsRepository;

/**
 * @extends ServiceEntityRepository<HttpRequestLog>
 */
#[AsRepository(entityClass: HttpRequestLog::class)]
class HttpRequestLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HttpRequestLog::class);
    }

    public function save(HttpRequestLog $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(HttpRequestLog $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return HttpRequestLog[]
     */
    public function findByTask(HttpRequestTask $task): array
    {
        /** @phpstan-ignore return.type */
        return $this->createQueryBuilder('l')
            ->where('l.task = :task')
            ->setParameter('task', $task)
            ->orderBy('l.attemptNumber', 'ASC')
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * @return HttpRequestLog[]
     */
    public function findRecentLogs(int $limit = 100): array
    {
        /** @phpstan-ignore return.type */
        return $this->createQueryBuilder('l')
            ->orderBy('l.executedTime', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * @return HttpRequestLog[]
     */
    public function findFailedLogs(int $limit = 100): array
    {
        /** @phpstan-ignore return.type */
        return $this->createQueryBuilder('l')
            ->where('l.result != :success')
            ->setParameter('success', HttpRequestLog::RESULT_SUCCESS)
            ->orderBy('l.executedTime', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult()
        ;
    }

    public function getLatestLogForTask(HttpRequestTask $task): ?HttpRequestLog
    {
        /** @phpstan-ignore return.type */
        return $this->createQueryBuilder('l')
            ->where('l.task = :task')
            ->setParameter('task', $task)
            ->orderBy('l.attemptNumber', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }

    /**
     * @return HttpRequestLog[]
     */
    public function findExpiredLogs(\DateTimeImmutable $before, int $limit = 100): array
    {
        /** @phpstan-ignore return.type */
        return $this->createQueryBuilder('l')
            ->where('l.createdTime < :before')
            ->setParameter('before', $before)
            ->orderBy('l.createdTime', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * @return HttpRequestLog[]
     */
    public function findByResult(string $result, int $limit = 100): array
    {
        /** @phpstan-ignore return.type */
        return $this->createQueryBuilder('l')
            ->where('l.result = :result')
            ->setParameter('result', $result)
            ->orderBy('l.createdTime', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult()
        ;
    }

    public function getAverageResponseTime(?\DateTimeInterface $since = null): ?float
    {
        $qb = $this->createQueryBuilder('l')
            ->select('AVG(l.responseTime) as avg_time')
            ->where('l.result = :success')
            ->setParameter('success', HttpRequestLog::RESULT_SUCCESS)
        ;

        if (null !== $since) {
            $qb->andWhere('l.createdTime >= :since')
                ->setParameter('since', $since)
            ;
        }

        $result = $qb->getQuery()->getSingleScalarResult();

        return null !== $result ? (float) $result : null;
    }

    /**
     * @return array<string, int>
     */
    public function getResultStatistics(?\DateTimeInterface $since = null): array
    {
        $qb = $this->createQueryBuilder('l')
            ->select('l.result', 'COUNT(l.id) as count')
            ->groupBy('l.result')
        ;

        if (null !== $since) {
            $qb->andWhere('l.createdTime >= :since')
                ->setParameter('since', $since)
            ;
        }

        /** @var array<array{result: string, count: string}> $results */
        $results = $qb->getQuery()->getResult();
        $statistics = [];

        foreach ($results as $row) {
            $statistics[$row['result']] = (int) $row['count'];
        }

        return $statistics;
    }

    /**
     * @return array<int, int>
     */
    public function getResponseCodeDistribution(?\DateTimeInterface $since = null): array
    {
        $qb = $this->createQueryBuilder('l')
            ->select('l.responseCode', 'COUNT(l.id) as count')
            ->where('l.responseCode IS NOT NULL')
            ->groupBy('l.responseCode')
            ->orderBy('l.responseCode', 'ASC')
        ;

        if (null !== $since) {
            $qb->andWhere('l.createdTime >= :since')
                ->setParameter('since', $since)
            ;
        }

        /** @var array<array{responseCode: int, count: string}> $results */
        $results = $qb->getQuery()->getResult();
        $distribution = [];

        foreach ($results as $row) {
            $distribution[(int) $row['responseCode']] = (int) $row['count'];
        }

        return $distribution;
    }

    public function deleteOldLogs(\DateTimeInterface $before): int
    {
        $qb = $this->createQueryBuilder('l')
            ->delete()
            ->where('l.createdTime < :before')
            ->setParameter('before', $before)
        ;

        $result = $qb->getQuery()->execute();

        return is_int($result) ? $result : 0;
    }

    public function countByTask(HttpRequestTask $task): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.task = :task')
            ->setParameter('task', $task)
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }
}
