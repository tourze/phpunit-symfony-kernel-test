<?php

namespace Tourze\PHPUnitSymfonyKernelTest\Exception;

/**
 * 服务未找到异常
 *
 * 当容器中不存在指定服务或服务类型不匹配时抛出
 */
class ServiceNotFoundException extends \LogicException
{
}
