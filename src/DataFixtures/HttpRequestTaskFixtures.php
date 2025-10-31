<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;
use Tourze\HttpRequestTaskBundle\Exception\JsonEncodingException;

class HttpRequestTaskFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $statuses = [
            HttpRequestTask::STATUS_PENDING,
            HttpRequestTask::STATUS_PROCESSING,
            HttpRequestTask::STATUS_COMPLETED,
            HttpRequestTask::STATUS_FAILED,
            HttpRequestTask::STATUS_CANCELLED,
        ];

        $methods = [
            HttpRequestTask::METHOD_GET,
            HttpRequestTask::METHOD_POST,
            HttpRequestTask::METHOD_PUT,
            HttpRequestTask::METHOD_DELETE,
        ];

        for ($i = 0; $i < 10; ++$i) {
            $task = new HttpRequestTask();
            $task->setUrl('https://jsonplaceholder.typicode.com/posts/' . ($i + 1));
            $task->setMethod($methods[$i % count($methods)]);
            $task->setStatus($statuses[$i % count($statuses)]);
            $task->setPriority($i % 3 + 1);
            $task->setMaxAttempts(3);
            $task->setTimeout(30);
            $task->setRetryDelay(1000);
            $task->setRetryMultiplier(2.0);

            if (HttpRequestTask::METHOD_POST === $task->getMethod()) {
                $task->setHeaders(['Content-Type' => 'application/json']);
                $body = json_encode(['data' => 'test-' . $i]);
                if (false === $body) {
                    throw new JsonEncodingException('Failed to encode JSON body');
                }
                $task->setBody($body);
                $task->setContentType('application/json');
            }

            if (0 === $i % 3) {
                $task->setScheduledTime(new \DateTimeImmutable('+' . ($i + 1) . ' hours'));
            }

            if (HttpRequestTask::STATUS_COMPLETED === $task->getStatus()) {
                $task->setCompletedTime(new \DateTimeImmutable());
                $task->setLastResponseCode(200);
                $task->setLastResponseBody('{"success": true}');
            }

            if (HttpRequestTask::STATUS_FAILED === $task->getStatus()) {
                $task->setLastErrorMessage('Connection timeout');
                $task->setLastResponseCode(0);
            }

            $manager->persist($task);
            $this->addReference('http-request-task-' . $i, $task);
        }

        $manager->flush();
    }
}
