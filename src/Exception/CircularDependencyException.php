<?php

namespace Tourze\PHPUnitSymfonyKernelTest\Exception;

/**
 * 循环依赖异常
 *
 * 当检测到表依赖循环时抛出
 */
class CircularDependencyException extends \RuntimeException
{
}
