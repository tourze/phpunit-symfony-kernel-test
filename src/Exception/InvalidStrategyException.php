<?php

namespace Tourze\PHPUnitSymfonyKernelTest\Exception;

/**
 * 无效策略异常
 *
 * 当提供的清理策略不被支持时抛出
 */
class InvalidStrategyException extends \InvalidArgumentException
{
}
