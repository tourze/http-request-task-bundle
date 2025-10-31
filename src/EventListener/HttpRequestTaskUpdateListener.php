<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;

#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: HttpRequestTask::class)]
class HttpRequestTaskUpdateListener
{
    public function preUpdate(HttpRequestTask $task, PreUpdateEventArgs $args): void
    {
        $task->setUpdatedTime(new \DateTimeImmutable());
    }
}
