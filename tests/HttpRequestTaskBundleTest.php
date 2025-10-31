<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tourze\HttpRequestTaskBundle\HttpRequestTaskBundle;
use Tourze\PHPUnitSymfonyKernelTest\AbstractBundleTestCase;

/**
 * @internal
 */
#[CoversClass(HttpRequestTaskBundle::class)]
#[RunTestsInSeparateProcesses]
final class HttpRequestTaskBundleTest extends AbstractBundleTestCase
{
}
