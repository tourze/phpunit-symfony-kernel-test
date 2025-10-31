<?php

namespace Tourze\PHPUnitSymfonyKernelTest;

/**
 * 强制要求事件订阅使用这个测试用例
 */
abstract class AbstractEventSubscriberTestCase extends AbstractIntegrationTestCase
{
    /**
     * {@inheritdoc}
     */
    protected function onSetUp(): void
    {
        // 事件订阅器测试的默认设置逻辑
        // 子类可以重写此方法添加特定的设置
    }
}
