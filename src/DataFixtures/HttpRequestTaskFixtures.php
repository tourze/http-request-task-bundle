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
        // 只使用不会触发 Messenger 消息分发的状态
        // PENDING 和 FAILED 状态会显示 execute/retry/cancel 动作，
        // 这些动作在测试环境中会导致内存耗尽
        $statuses = [
            HttpRequestTask::STATUS_PROCESSING,
            HttpRequestTask::STATUS_COMPLETED,
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

            // FAILED 状态不再创建，以避免测试环境中触发重试动作导致内存耗尽

            $manager->persist($task);
            $this->addReference('http-request-task-' . $i, $task);
        }

        $manager->flush();
    }
}
