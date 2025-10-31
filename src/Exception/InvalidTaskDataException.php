<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Exception;

class InvalidTaskDataException extends HttpRequestTaskException
{
    public function __construct(string $message = 'Invalid task data', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
