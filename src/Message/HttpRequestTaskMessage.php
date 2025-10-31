<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Message;

use Tourze\AsyncContracts\AsyncMessageInterface;

class HttpRequestTaskMessage implements AsyncMessageInterface
{
    private int $taskId;

    public function __construct(int $taskId)
    {
        $this->taskId = $taskId;
    }

    public function getTaskId(): int
    {
        return $this->taskId;
    }
}
