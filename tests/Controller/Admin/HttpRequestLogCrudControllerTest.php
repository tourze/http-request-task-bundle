<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Exception\ForbiddenActionException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use Tourze\HttpRequestTaskBundle\Controller\Admin\HttpRequestLogCrudController;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestLog;
use Tourze\PHPUnitSymfonyWebTest\AbstractEasyAdminControllerTestCase;

/**
 * @internal
 */
#[CoversClass(HttpRequestLogCrudController::class)]
#[RunTestsInSeparateProcesses]
class HttpRequestLogCrudControllerTest extends AbstractEasyAdminControllerTestCase
{
    protected function getControllerService(): HttpRequestLogCrudController
    {
        return new HttpRequestLogCrudController();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideIndexPageHeaders(): iterable
    {
        yield 'ID' => ['ID'];
        yield 'Task' => ['关联任务'];
        yield 'Attempt Number' => ['尝试次数'];
        yield 'Result' => ['执行结果'];
        yield 'Response Code' => ['响应状态码'];
        yield 'Response Time' => ['响应时间'];
        yield 'Executed Time' => ['执行时间'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideEditPageFields(): iterable
    {
        // HttpRequestLogCrudController 禁用了 EDIT 操作，但父类测试框架要求数据提供器不为空
        // 由于不能修改测试框架的final方法，我们在测试运行时使用expectException来处理
        yield 'task' => ['task'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNewPageFields(): iterable
    {
        // HttpRequestLogCrudController 禁用了 NEW 操作，但父类测试框架要求数据提供器不为空
        // 由于不能修改测试框架的final方法，我们在测试运行时使用expectException来处理
        yield 'task' => ['task'];
    }

    /**
     * 重写基类的 ensureAdminActionAttributesAreValid 方法，为没有自定义动作的控制器添加适当断言
     */
    #[Test]
    public function testCustomControllerConfiguration(): void
    {
        $controller = $this->getControllerService();

        $this->assertNoUnexpectedCustomMethods($controller);
        $this->assertCorrectActionConfiguration($controller);

        // 确保控制器正确配置了实体类
        self::assertEquals(HttpRequestLog::class, HttpRequestLogCrudController::getEntityFqcn());
    }

    private function assertNoUnexpectedCustomMethods(object $controller): void
    {
        $reflection = new \ReflectionClass($controller);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
        $customActionMethods = $this->getCustomActionMethods($methods, $reflection);

        $allowedMethods = [
            'getEntityFqcn', 'configureCrud', 'configureActions', 'configureFields', 'configureFilters',
            'formatResponseTime', 'formatFileSize', 'formatTaskValue', 'formatAttemptNumber',
            'formatResponseCode', 'formatResponseTimeField', 'formatRequestBody', 'formatResponseBody', 'formatErrorMessage',
        ];

        $unexpectedMethods = $this->findUnexpectedMethods($customActionMethods, $allowedMethods);

        self::assertEmpty($unexpectedMethods,
            sprintf('HttpRequestLogCrudController 不应该有自定义动作方法，但发现了: %s',
                implode(', ', $unexpectedMethods)));
    }

    /**
     * @param \ReflectionMethod[] $methods
     * @param \ReflectionClass<object> $reflection
     * @return \ReflectionMethod[]
     */
    private function getCustomActionMethods(array $methods, \ReflectionClass $reflection): array
    {
        $customActionMethods = [];
        foreach ($methods as $method) {
            $filename = $method->getFileName();
            if (false !== $filename && !str_contains($filename, '/vendor/') && $method->getDeclaringClass()->getName() === $reflection->getName()) {
                $customActionMethods[] = $method;
            }
        }

        return $customActionMethods;
    }

    /**
     * @param \ReflectionMethod[] $customActionMethods
     * @param string[] $allowedMethods
     * @return string[]
     */
    private function findUnexpectedMethods(array $customActionMethods, array $allowedMethods): array
    {
        $unexpectedMethods = [];
        foreach ($customActionMethods as $method) {
            if (!in_array($method->getName(), $allowedMethods, true)) {
                $unexpectedMethods[] = $method->getName();
            }
        }

        return $unexpectedMethods;
    }

    private function assertCorrectActionConfiguration(object $controller): void
    {
        $actions = Actions::new();
        if (method_exists($controller, 'configureActions')) {
            $controller->configureActions($actions);
        }

        $actionDto = $actions->getAsDto(Crud::PAGE_INDEX);
        $configuredActions = $actionDto->getActions();

        $actionNames = $this->extractActionNames($configuredActions);

        self::assertContains(Action::DETAIL, $actionNames, 'INDEX 页面应该有 DETAIL 操作');
        self::assertNotContains(Action::NEW, $actionNames, 'INDEX 页面不应该有 NEW 操作');
        self::assertNotContains(Action::EDIT, $actionNames, 'INDEX 页面不应该有 EDIT 操作');
        self::assertNotContains(Action::DELETE, $actionNames, 'INDEX 页面不应该有 DELETE 操作');
    }

    /**
     * @param iterable<mixed> $configuredActions
     * @return string[]
     */
    private function extractActionNames(iterable $configuredActions): array
    {
        $actionNames = [];
        foreach ($configuredActions as $action) {
            if (is_object($action) && method_exists($action, 'getName')) {
                $actionNames[] = $action->getName();
            }
        }

        return $actionNames;
    }

    /**
     * 测试NEW操作是否正确被禁用
     * 重写父类的数据提供器驱动的测试，因为操作被禁用时会抛出ForbiddenActionException
     */
    #[Test]
    public function testNewActionIsDisabled(): void
    {
        $client = $this->createAuthenticatedClient();

        // 期望抛出ForbiddenActionException
        $this->expectException(ForbiddenActionException::class);
        $this->expectExceptionMessage('You don\'t have enough permissions to run the "new" action');

        // 尝试访问NEW页面应该抛出异常
        $client->request('GET', $this->generateAdminUrl(Action::NEW));
    }

    /**
     * 测试EDIT操作是否正确被禁用
     * 重写父类的数据提供器驱动的测试，因为操作被禁用时会抛出ForbiddenActionException
     */
    #[Test]
    public function testEditActionIsDisabled(): void
    {
        $client = $this->createAuthenticatedClient();

        // 首先获取一条记录的ID
        $crawler = $client->request('GET', $this->generateAdminUrl(Action::INDEX));
        $this->assertResponseIsSuccessful();

        $firstRecordId = $crawler->filter('table tbody tr[data-id]')->first()->attr('data-id');
        self::assertNotEmpty($firstRecordId, 'Could not find a record ID on the index page to test the edit action.');

        // 期望抛出ForbiddenActionException
        $this->expectException(ForbiddenActionException::class);
        $this->expectExceptionMessage('You don\'t have enough permissions to run the "edit" action');

        // 尝试访问EDIT页面应该抛出异常
        $client->request('GET', $this->generateAdminUrl(Action::EDIT, ['entityId' => $firstRecordId]));
    }

    /**
     * 测试DELETE操作是否正确被禁用
     */
    #[Test]
    public function testDeleteActionIsDisabled(): void
    {
        $client = $this->createAuthenticatedClient();

        // 首先获取一条记录的ID
        $crawler = $client->request('GET', $this->generateAdminUrl(Action::INDEX));
        $this->assertResponseIsSuccessful();

        $firstRecordId = $crawler->filter('table tbody tr[data-id]')->first()->attr('data-id');
        self::assertNotEmpty($firstRecordId, 'Could not find a record ID on the index page to test the delete action.');

        // 期望抛出ForbiddenActionException
        $this->expectException(ForbiddenActionException::class);
        $this->expectExceptionMessage('You don\'t have enough permissions to run the "delete" action');

        // DELETE操作需要POST请求
        $client->request('POST', $this->generateAdminUrl(Action::DELETE, ['entityId' => $firstRecordId]));
    }
}
