<?php

namespace Tourze\PHPUnitSymfonyKernelTest;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * 针对 Symfony Message Handler 做的通用测试基类
 */
#[RunTestsInSeparateProcesses]
abstract class AbstractMessageHandlerTestCase extends AbstractIntegrationTestCase
{
}
