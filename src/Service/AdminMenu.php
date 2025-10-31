<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Service;

use Knp\Menu\ItemInterface;
use Tourze\EasyAdminMenuBundle\Service\LinkGeneratorInterface;
use Tourze\EasyAdminMenuBundle\Service\MenuProviderInterface;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestLog;
use Tourze\HttpRequestTaskBundle\Entity\HttpRequestTask;

/**
 * HTTP请求任务管理菜单服务
 */
readonly class AdminMenu implements MenuProviderInterface
{
    public function __construct(
        private LinkGeneratorInterface $linkGenerator,
    ) {
    }

    public function __invoke(ItemInterface $item): void
    {
        $httpRequestMenu = $item->getChild('HTTP请求任务');
        if (null === $httpRequestMenu) {
            $httpRequestMenu = $item->addChild('HTTP请求任务');
        }

        // HTTP请求任务菜单
        $httpRequestMenu->addChild('任务管理')
            ->setUri($this->linkGenerator->getCurdListPage(HttpRequestTask::class))
            ->setAttribute('icon', 'fas fa-tasks')
        ;

        // HTTP请求日志菜单
        $httpRequestMenu->addChild('执行日志')
            ->setUri($this->linkGenerator->getCurdListPage(HttpRequestLog::class))
            ->setAttribute('icon', 'fas fa-history')
        ;
    }
}
