<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestLog;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;

class HttpRequestLogFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $task = $this->getReference('http-request-task-' . $i % 5, HttpRequestTask::class);

            $log = new HttpRequestLog();
            $log->setTask($task);
            $log->setAttemptNumber($i % 3 + 1);
            $log->setExecutedTime(new \DateTimeImmutable(sprintf('-%d hours', $i)));
            $log->setRequestHeaders(['Content-Type' => 'application/json']);
            $log->setRequestBody('{"test": "data"}');

            if (0 === $i % 2) {
                $log->setResult(HttpRequestLog::RESULT_SUCCESS);
                $log->setResponseCode(200);
                $log->setResponseHeaders(['Content-Type' => 'application/json']);
                $log->setResponseBody('{"success": true}');
            } else {
                $log->setResult(HttpRequestLog::RESULT_FAILURE);
                $log->setResponseCode(500);
                $log->setErrorMessage('Internal Server Error');
            }

            $log->setResponseTime(random_int(100, 5000));

            $manager->persist($log);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            HttpRequestTaskFixtures::class,
        ];
    }
}
