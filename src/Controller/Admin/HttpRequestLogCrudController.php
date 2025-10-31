<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminCrud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestLog;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;

#[AdminCrud(
    routePath: '/http-request-task/log',
    routeName: 'http_request_task_log'
)]
final class HttpRequestLogCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return HttpRequestLog::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('HTTP请求日志')
            ->setEntityLabelInPlural('HTTP请求执行日志')
            ->setPageTitle('index', 'HTTP请求执行日志')
            ->setPageTitle('detail', 'HTTP请求日志详情')
            ->setHelp('index', '查看HTTP请求任务的详细执行日志和结果')
            ->setSearchFields(['task.uuid', 'task.url', 'result'])
            ->setDefaultSort(['executedTime' => 'DESC'])
            ->setPaginatorPageSize(30)
            ->showEntityActionsInlined()
        ;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->reorder(Crud::PAGE_INDEX, [Action::DETAIL])
        ;
    }

    public function configureFields(string $pageName): iterable
    {
        $resultChoices = [
            '成功' => HttpRequestLog::RESULT_SUCCESS,
            '失败' => HttpRequestLog::RESULT_FAILURE,
            '网络错误' => HttpRequestLog::RESULT_NETWORK_ERROR,
            '超时' => HttpRequestLog::RESULT_TIMEOUT,
        ];

        yield IdField::new('id', 'ID')
            ->onlyOnIndex()
            ->setMaxLength(9999)
        ;

        yield AssociationField::new('task', '关联任务')
            ->formatValue(function (?HttpRequestTask $task) {
                return $this->formatTaskValue($task);
            })
        ;

        yield IntegerField::new('attemptNumber', '尝试次数')
            ->formatValue(function (int $value, HttpRequestLog $log) {
                return $this->formatAttemptNumber($value, $log);
            })
        ;

        yield ChoiceField::new('result', '执行结果')
            ->setChoices($resultChoices)
            ->renderAsBadges([
                HttpRequestLog::RESULT_SUCCESS => 'success',
                HttpRequestLog::RESULT_FAILURE => 'danger',
                HttpRequestLog::RESULT_NETWORK_ERROR => 'warning',
                HttpRequestLog::RESULT_TIMEOUT => 'secondary',
            ])
        ;

        yield IntegerField::new('responseCode', '响应状态码')
            ->formatValue(function (?int $value) {
                return $this->formatResponseCode($value);
            })
        ;

        yield NumberField::new('responseTime', '响应时间')
            ->formatValue(function (?int $value) {
                return $this->formatResponseTimeField($value);
            })
            ->setHelp('请求执行耗时')
        ;

        yield DateTimeField::new('executedTime', '执行时间')
            ->setFormat('yyyy-MM-dd HH:mm:ss')
        ;

        yield DateTimeField::new('createdTime', '记录时间')
            ->hideOnIndex()
            ->setFormat('yyyy-MM-dd HH:mm:ss')
        ;

        if (Crud::PAGE_DETAIL === $pageName) {
            yield ArrayField::new('requestHeaders', '请求头信息')
                ->onlyOnDetail()
            ;

            yield TextareaField::new('requestBody', '请求体内容')
                ->onlyOnDetail()
                ->formatValue(function (?string $value) {
                    return $this->formatRequestBody($value);
                })
            ;

            yield ArrayField::new('responseHeaders', '响应头信息')
                ->onlyOnDetail()
            ;

            yield TextareaField::new('responseBody', '响应体内容')
                ->onlyOnDetail()
                ->formatValue(function (?string $value) {
                    return $this->formatResponseBody($value);
                })
            ;

            yield TextareaField::new('errorMessage', '错误信息')
                ->onlyOnDetail()
                ->formatValue(function (?string $value) {
                    return $this->formatErrorMessage($value);
                })
            ;
        }
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('result', '执行结果')->setChoices([
                '成功' => HttpRequestLog::RESULT_SUCCESS,
                '失败' => HttpRequestLog::RESULT_FAILURE,
                '网络错误' => HttpRequestLog::RESULT_NETWORK_ERROR,
                '超时' => HttpRequestLog::RESULT_TIMEOUT,
            ]))
            ->add(EntityFilter::new('task', '关联任务'))
            ->add(NumericFilter::new('attemptNumber', '尝试次数'))
            ->add(NumericFilter::new('responseCode', '响应状态码'))
            ->add(NumericFilter::new('responseTime', '响应时间'))
            ->add(DateTimeFilter::new('executedTime', '执行时间'))
            ->add(DateTimeFilter::new('createdTime', '记录时间'))
        ;
    }

    private function formatResponseTime(int $milliseconds): string
    {
        if ($milliseconds < 1000) {
            return $milliseconds . 'ms';
        }

        if ($milliseconds < 60000) {
            return round($milliseconds / 1000, 2) . 's';
        }

        $minutes = intval($milliseconds / 60000);
        $seconds = round(($milliseconds % 60000) / 1000, 1);

        return sprintf('%dm %ss', $minutes, $seconds);
    }

    private function formatFileSize(int $bytes): string
    {
        if (0 === $bytes) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;
        $size = $bytes;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            ++$unitIndex;
        }

        $decimals = 0 === $unitIndex ? 0 : 2;

        return round($size, $decimals) . ' ' . $units[$unitIndex];
    }

    private function formatTaskValue(?HttpRequestTask $task): string
    {
        if (null === $task) {
            return '<span class="text-muted">N/A</span>';
        }

        return sprintf(
            '<div><strong>%s</strong><br><small class="text-muted">%s</small></div>',
            $task->getMethod() . ' ' . $task->getUrl(),
            $task->getUuid()
        );
    }

    private function formatAttemptNumber(int $value, HttpRequestLog $log): string
    {
        $maxAttempts = $log->getTask()->getMaxAttempts();
        $class = $value >= $maxAttempts ? 'text-danger' :
                ($value > $maxAttempts * 0.7 ? 'text-warning' : 'text-primary');

        return sprintf('<span class="%s">第%d次</span>', $class, $value);
    }

    private function formatResponseCode(?int $value): string
    {
        if (null === $value) {
            return '<span class="text-muted">无响应</span>';
        }

        $class = match (true) {
            $value >= 200 && $value < 300 => 'text-success',
            $value >= 300 && $value < 400 => 'text-info',
            $value >= 400 && $value < 500 => 'text-warning',
            $value >= 500 => 'text-danger',
            default => 'text-muted',
        };

        $text = match (true) {
            $value >= 200 && $value < 300 => '成功',
            $value >= 300 && $value < 400 => '重定向',
            $value >= 400 && $value < 500 => '客户端错误',
            $value >= 500 => '服务器错误',
            default => '未知',
        };

        return sprintf('<span class="%s"><strong>%d</strong> <small>(%s)</small></span>', $class, $value, $text);
    }

    private function formatResponseTimeField(?int $value): string
    {
        return $this->formatResponseTime($value ?? 0);
    }

    private function formatRequestBody(?string $value): string
    {
        if (null === $value) {
            return '<span class="text-muted">无请求体</span>';
        }

        return mb_strlen($value) > 2000 ?
            mb_substr($value, 0, 2000) . "\n\n[内容已截断...]" : $value;
    }

    private function formatResponseBody(?string $value): string
    {
        if (null === $value) {
            return '<span class="text-muted">无响应体</span>';
        }

        $size = mb_strlen($value);
        $sizeText = $this->formatFileSize($size);

        $content = $size > 3000 ?
            mb_substr($value, 0, 3000) . "\n\n[内容已截断，完整大小: {$sizeText}]" : $value;

        return sprintf('<div><small class="text-muted">响应大小: %s</small><hr>%s</div>', $sizeText, $content);
    }

    private function formatErrorMessage(?string $value): string
    {
        return null === $value ? '<span class="text-muted">无错误</span>' : $value;
    }
}
