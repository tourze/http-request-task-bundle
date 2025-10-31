<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Exception;

class MissingRefererException extends HttpRequestTaskException
{
    public function __construct(string $message = 'Missing referer header', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
