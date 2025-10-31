<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminAction;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminCrud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;
use Tourze\HttpRequestTaskBundle\Exception\MissingRefererException;
use Tourze\HttpRequestTaskBundle\Service\HttpRequestTaskService;

#[AdminCrud(
    routePath: '/http-request-task/task',
    routeName: 'http_request_task_task'
)]
final class HttpRequestTaskCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return HttpRequestTask::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('HTTP请求任务')
            ->setEntityLabelInPlural('HTTP请求任务')
            ->setPageTitle('index', 'HTTP请求任务管理')
            ->setPageTitle('new', '创建HTTP请求任务')
            ->setPageTitle('edit', '编辑HTTP请求任务')
            ->setPageTitle('detail', 'HTTP请求任务详情')
            ->setHelp('index', '管理HTTP请求任务的创建、执行和监控')
            ->setSearchFields(['uuid', 'url', 'method', 'status'])
            ->setDefaultSort(['createdTime' => 'DESC'])
            ->setPaginatorPageSize(20)
            ->showEntityActionsInlined()
        ;
    }

    public function configureActions(Actions $actions): Actions
    {
        $retry = Action::new('retry', '重试', 'fas fa-redo')
            ->linkToCrudAction('retryTask')
            ->setCssClass('btn btn-warning')
            ->displayIf(static function (HttpRequestTask $entity) {
                return HttpRequestTask::STATUS_FAILED === $entity->getStatus() && $entity->canRetry();
            })
        ;

        $cancel = Action::new('cancel', '取消', 'fas fa-times')
            ->linkToCrudAction('cancelTask')
            ->setCssClass('btn btn-danger')
            ->displayIf(static function (HttpRequestTask $entity) {
                return in_array($entity->getStatus(), [
                    HttpRequestTask::STATUS_PENDING,
                    HttpRequestTask::STATUS_FAILED,
                ], true);
            })
        ;

        $execute = Action::new('execute', '立即执行', 'fas fa-play')
            ->linkToCrudAction('executeTask')
            ->setCssClass('btn btn-success')
            ->displayIf(static function (HttpRequestTask $entity) {
                return HttpRequestTask::STATUS_PENDING === $entity->getStatus();
            })
        ;

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $execute)
            ->add(Crud::PAGE_INDEX, $retry)
            ->add(Crud::PAGE_INDEX, $cancel)
            ->add(Crud::PAGE_DETAIL, $execute)
            ->add(Crud::PAGE_DETAIL, $retry)
            ->add(Crud::PAGE_DETAIL, $cancel)
            ->reorder(Crud::PAGE_INDEX, [Action::DETAIL, 'execute', 'retry', 'cancel'])
            ->disable(Action::DELETE)
        ;
    }

    public function configureFields(string $pageName): iterable
    {
        // 基础字段 - 所有页面都显示
        yield from $this->getBasicFields();

        // 页面特定字段
        yield from match ($pageName) {
            Crud::PAGE_INDEX => $this->getIndexFields(),
            Crud::PAGE_DETAIL => $this->getDetailFields(),
            Crud::PAGE_EDIT, Crud::PAGE_NEW => $this->getFormFields(),
            default => [],
        };
    }

    /**
     * @return iterable<FieldInterface>
     */
    private function getBasicFields(): iterable
    {
        yield IdField::new('id', 'ID')->onlyOnIndex()->setMaxLength(9999);
        yield TextField::new('uuid', 'UUID')->hideOnIndex()->onlyOnDetail();
        yield TextField::new('url', '请求URL')
            ->setMaxLength(100)
            ->formatValue(static function ($value): string {
                $stringValue = (string) $value;

                return mb_strlen($stringValue) > 100 ? mb_substr($stringValue, 0, 100) . '...' : $stringValue;
            })
        ;

        yield $this->createMethodField();
        yield $this->createStatusField();
        yield $this->createPriorityField();
        yield $this->createDateTimeField('createdTime', '创建时间')->hideOnForm();
    }

    /**
     * @return iterable<FieldInterface>
     */
    private function getIndexFields(): iterable
    {
        yield IntegerField::new('headerCount', '请求头数量')
            ->formatValue(static fn ($value, HttpRequestTask $entity) => sprintf('<span class="badge badge-info">%d 个</span>', count($entity->getHeaders()))
            )
            ->onlyOnIndex()
        ;
    }

    /**
     * @return iterable<FieldInterface>
     */
    private function getDetailFields(): iterable
    {
        yield from $this->getAttemptFields();
        yield from $this->getTimestampFields();
        yield from $this->getRequestFields();
        yield from $this->getResponseFields();

        yield AssociationField::new('logs', '执行日志')
            ->onlyOnDetail()
            ->setTemplatePath('@EasyAdmin/crud/field/association.html.twig')
        ;
    }

    /**
     * @return iterable<FieldInterface>
     */
    private function getFormFields(): iterable
    {
        yield from $this->getRequestFields();
        yield from $this->getConfigFields();
    }

    private function createMethodField(): ChoiceField
    {
        return ChoiceField::new('method', '请求方法')
            ->setChoices($this->getMethodChoices())
            ->renderAsBadges($this->getMethodBadges())
        ;
    }

    private function createStatusField(): ChoiceField
    {
        return ChoiceField::new('status', '执行状态')
            ->setChoices($this->getStatusChoices())
            ->renderAsBadges($this->getStatusBadges())
        ;
    }

    private function createPriorityField(): ChoiceField
    {
        return ChoiceField::new('priority', '优先级')
            ->setChoices($this->getPriorityChoices())
            ->renderAsBadges($this->getPriorityBadges())
        ;
    }

    private function createDateTimeField(string $property, string $label): DateTimeField
    {
        return DateTimeField::new($property, $label)->setFormat('yyyy-MM-dd HH:mm:ss');
    }

    /**
     * @return iterable<FieldInterface>
     */
    private function getAttemptFields(): iterable
    {
        yield IntegerField::new('attempts', '已尝试次数')
            ->onlyOnDetail()
            ->formatValue(static function ($value, HttpRequestTask $entity): string {
                $attempts = (int) $value;
                $max = $entity->getMaxAttempts();
                $class = $attempts >= $max ? 'text-danger' : ($attempts > $max * 0.7 ? 'text-warning' : 'text-success');

                return sprintf('<span class="%s">%d/%d</span>', $class, $attempts, $max);
            })
        ;

        yield IntegerField::new('maxAttempts', '最大尝试次数')->hideOnIndex();
    }

    /**
     * @return iterable<FieldInterface>
     */
    private function getTimestampFields(): iterable
    {
        $fields = ['scheduledTime' => '计划执行时间', 'startedTime' => '开始执行时间',
            'completedTime' => '完成时间', 'lastAttemptTime' => '最后尝试时间'];

        foreach ($fields as $property => $label) {
            yield $this->createDateTimeField($property, $label)->hideOnIndex()->onlyOnDetail();
        }

        yield $this->createDateTimeField('updatedTime', '更新时间')
            ->hideOnIndex()->hideOnForm()->onlyOnDetail()
        ;
    }

    /**
     * @return iterable<FieldInterface>
     */
    private function getRequestFields(): iterable
    {
        yield ArrayField::new('headers', '请求头')
            ->hideOnIndex()
            ->setHelp('HTTP请求头键值对，例如：Content-Type: application/json')
        ;

        yield TextareaField::new('body', '请求体')->hideOnIndex();
        yield TextField::new('contentType', '内容类型')->hideOnIndex();
        yield ArrayField::new('metadata', '元数据')
            ->hideOnIndex()
            ->setHelp('任务相关的元数据键值对')
        ;
    }

    /**
     * @return iterable<FieldInterface>
     */
    private function getConfigFields(): iterable
    {
        $configs = [
            ['timeout', '超时时间(秒)', '请求超时时间，单位秒，默认30秒'],
            ['retryDelay', '重试延迟(毫秒)', '重试间隔时间，单位毫秒，默认1000毫秒'],
            ['rateLimitPerSecond', '限流速率(次/秒)', '每秒最大请求次数'],
        ];

        foreach ($configs as [$property, $label, $help]) {
            yield IntegerField::new($property, $label)->setHelp($help);
        }

        yield NumberField::new('retryMultiplier', '重试延迟倍数')
            ->setHelp('每次重试延迟时间的递增倍数，默认2.0')
            ->setNumDecimals(1)
        ;

        yield TextField::new('rateLimitKey', '限流键')->setHelp('用于限流控制的键值');
    }

    /**
     * @return iterable<FieldInterface>
     */
    private function getResponseFields(): iterable
    {
        yield IntegerField::new('lastResponseCode', '最后响应码')
            ->onlyOnDetail()
            ->formatValue(static function ($value): string {
                if (null === $value) {
                    return '<span class="text-muted">N/A</span>';
                }
                $responseCode = (int) $value;
                $class = match (true) {
                    $responseCode >= 200 && $responseCode < 300 => 'text-success',
                    $responseCode >= 400 && $responseCode < 500 => 'text-warning',
                    $responseCode >= 500 => 'text-danger',
                    default => 'text-info',
                };

                return sprintf('<span class="%s">%d</span>', $class, $responseCode);
            })
        ;

        yield TextareaField::new('lastErrorMessage', '最后错误信息')->onlyOnDetail();
        yield TextareaField::new('lastResponseBody', '最后响应内容')
            ->onlyOnDetail()
            ->formatValue(static function ($value): ?string {
                if (null === $value) {
                    return null;
                }

                $stringValue = (string) $value;

                return mb_strlen($stringValue) > 1000 ? mb_substr($stringValue, 0, 1000) . "\n\n[内容已截断...]" : $stringValue;
            })
        ;
    }

    /**
     * @return array<string, string>
     */
    private function getStatusChoices(): array
    {
        return [
            '等待执行' => HttpRequestTask::STATUS_PENDING,
            '执行中' => HttpRequestTask::STATUS_PROCESSING,
            '已完成' => HttpRequestTask::STATUS_COMPLETED,
            '执行失败' => HttpRequestTask::STATUS_FAILED,
            '已取消' => HttpRequestTask::STATUS_CANCELLED,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function getMethodChoices(): array
    {
        return [
            'GET' => HttpRequestTask::METHOD_GET,
            'POST' => HttpRequestTask::METHOD_POST,
            'PUT' => HttpRequestTask::METHOD_PUT,
            'PATCH' => HttpRequestTask::METHOD_PATCH,
            'DELETE' => HttpRequestTask::METHOD_DELETE,
            'HEAD' => HttpRequestTask::METHOD_HEAD,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function getPriorityChoices(): array
    {
        return [
            '高优先级' => HttpRequestTask::PRIORITY_HIGH,
            '普通优先级' => HttpRequestTask::PRIORITY_NORMAL,
            '低优先级' => HttpRequestTask::PRIORITY_LOW,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function getStatusBadges(): array
    {
        return [
            HttpRequestTask::STATUS_PENDING => 'warning',
            HttpRequestTask::STATUS_PROCESSING => 'info',
            HttpRequestTask::STATUS_COMPLETED => 'success',
            HttpRequestTask::STATUS_FAILED => 'danger',
            HttpRequestTask::STATUS_CANCELLED => 'secondary',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function getMethodBadges(): array
    {
        return [
            HttpRequestTask::METHOD_GET => 'primary',
            HttpRequestTask::METHOD_POST => 'success',
            HttpRequestTask::METHOD_PUT => 'warning',
            HttpRequestTask::METHOD_PATCH => 'info',
            HttpRequestTask::METHOD_DELETE => 'danger',
            HttpRequestTask::METHOD_HEAD => 'secondary',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function getPriorityBadges(): array
    {
        return [
            HttpRequestTask::PRIORITY_HIGH => 'danger',
            HttpRequestTask::PRIORITY_NORMAL => 'primary',
            HttpRequestTask::PRIORITY_LOW => 'secondary',
        ];
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status', '执行状态')->setChoices([
                '等待执行' => HttpRequestTask::STATUS_PENDING,
                '执行中' => HttpRequestTask::STATUS_PROCESSING,
                '已完成' => HttpRequestTask::STATUS_COMPLETED,
                '执行失败' => HttpRequestTask::STATUS_FAILED,
                '已取消' => HttpRequestTask::STATUS_CANCELLED,
            ]))
            ->add(ChoiceFilter::new('method', '请求方法')->setChoices([
                'GET' => HttpRequestTask::METHOD_GET,
                'POST' => HttpRequestTask::METHOD_POST,
                'PUT' => HttpRequestTask::METHOD_PUT,
                'PATCH' => HttpRequestTask::METHOD_PATCH,
                'DELETE' => HttpRequestTask::METHOD_DELETE,
                'HEAD' => HttpRequestTask::METHOD_HEAD,
            ]))
            ->add(ChoiceFilter::new('priority', '优先级')->setChoices([
                '高优先级' => HttpRequestTask::PRIORITY_HIGH,
                '普通优先级' => HttpRequestTask::PRIORITY_NORMAL,
                '低优先级' => HttpRequestTask::PRIORITY_LOW,
            ]))
            ->add(TextFilter::new('uuid', 'UUID'))
            ->add(TextFilter::new('url', '请求URL'))
            ->add(NumericFilter::new('lastResponseCode', '响应码'))
            ->add(DateTimeFilter::new('createdTime', '创建时间'))
            ->add(DateTimeFilter::new('scheduledTime', '计划执行时间'))
            ->add(DateTimeFilter::new('completedTime', '完成时间'))
        ;
    }

    #[AdminAction(routePath: '{entityId}/retry', routeName: 'retry_task')]
    public function retryTask(AdminContext $context, Request $request, HttpRequestTaskService $taskService): Response
    {
        $task = $context->getEntity()->getInstance();
        assert($task instanceof HttpRequestTask);

        try {
            $taskService->retryTask($task);
            $this->addFlash('success', sprintf('任务 %s 已加入重试队列', $task->getUuid()));
        } catch (\Exception $e) {
            $this->addFlash('danger', '重试任务失败: ' . $e->getMessage());
        }

        $referer = $context->getRequest()->headers->get('referer');
        if (null === $referer) {
            throw new MissingRefererException();
        }

        return $this->redirect($referer);
    }

    #[AdminAction(routePath: '{entityId}/cancel', routeName: 'cancel_task')]
    public function cancelTask(AdminContext $context, Request $request, HttpRequestTaskService $taskService): Response
    {
        $task = $context->getEntity()->getInstance();
        assert($task instanceof HttpRequestTask);

        try {
            $taskService->cancelTask($task);
            $this->addFlash('success', sprintf('任务 %s 已取消', $task->getUuid()));
        } catch (\Exception $e) {
            $this->addFlash('danger', '取消任务失败: ' . $e->getMessage());
        }

        $referer = $context->getRequest()->headers->get('referer');
        if (null === $referer) {
            throw new MissingRefererException();
        }

        return $this->redirect($referer);
    }

    #[AdminAction(routePath: '{entityId}/execute', routeName: 'execute_task')]
    public function executeTask(AdminContext $context, Request $request, HttpRequestTaskService $taskService): Response
    {
        $task = $context->getEntity()->getInstance();
        assert($task instanceof HttpRequestTask);

        try {
            $taskService->dispatchTask($task);
            $this->addFlash('success', sprintf('任务 %s 已加入执行队列', $task->getUuid()));
        } catch (\Exception $e) {
            $this->addFlash('danger', '执行任务失败: ' . $e->getMessage());
        }

        $referer = $context->getRequest()->headers->get('referer');
        if (null === $referer) {
            throw new MissingRefererException();
        }

        return $this->redirect($referer);
    }
}
