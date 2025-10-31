<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tourze\HttpRequestTaskBundle\DependencyInjection\HttpRequestTaskExtension;
use Tourze\PHPUnitSymfonyUnitTest\AbstractDependencyInjectionExtensionTestCase;
use Tourze\SymfonyDependencyServiceLoader\AutoExtension;

/**
 * @internal
 */
#[CoversClass(HttpRequestTaskExtension::class)]
final class HttpRequestTaskExtensionTest extends AbstractDependencyInjectionExtensionTestCase
{
    private HttpRequestTaskExtension $extension;

    private ContainerBuilder $container;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extension = new HttpRequestTaskExtension();
        $this->container = new ContainerBuilder();
        $this->container->setParameter('kernel.environment', 'test');
    }

    public function testLoadServices(): void
    {
        $configs = [];
        $this->extension->load($configs, $this->container);

        // 验证服务是否正确加载
        $definitions = $this->container->getDefinitions();
        $this->assertNotEmpty($definitions);

        $foundServiceDefinitions = false;
        foreach ($definitions as $id => $definition) {
            if (str_contains($id, 'HttpRequestTaskBundle')) {
                $foundServiceDefinitions = true;
                break;
            }
        }
        $this->assertTrue($foundServiceDefinitions);
    }

    public function testLoadWithDevEnvironment(): void
    {
        $this->container->setParameter('kernel.environment', 'dev');
        $configs = [];
        $this->extension->load($configs, $this->container);

        $this->assertInstanceOf(ContainerBuilder::class, $this->container);
    }

    public function testLoadWithTestEnvironment(): void
    {
        $this->container->setParameter('kernel.environment', 'test');
        $configs = [];
        $this->extension->load($configs, $this->container);

        $this->assertInstanceOf(ContainerBuilder::class, $this->container);
    }

    public function testLoadWithProdEnvironment(): void
    {
        $this->container->setParameter('kernel.environment', 'prod');
        $configs = [];
        $this->extension->load($configs, $this->container);

        $this->assertInstanceOf(ContainerBuilder::class, $this->container);
    }

    public function testInstanceOfExtension(): void
    {
        $this->assertInstanceOf(AutoExtension::class, $this->extension);
    }
}
