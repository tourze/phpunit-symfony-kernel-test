<?php

namespace Tourze\PHPUnitSymfonyKernelTest\Exception;

/**
 * 事务策略异常
 *
 * 当事务策略未实现或不可用时抛出
 */
class TransactionStrategyException extends \LogicException
{
}
