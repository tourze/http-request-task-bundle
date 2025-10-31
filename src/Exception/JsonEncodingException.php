<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Exception;

class JsonEncodingException extends HttpRequestTaskException
{
    public function __construct(string $message = 'Failed to encode JSON', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
