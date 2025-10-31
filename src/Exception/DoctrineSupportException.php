<?php

namespace Tourze\PHPUnitSymfonyKernelTest\Exception;

/**
 * 当 Doctrine 支持不可用时抛出的异常
 */
class DoctrineSupportException extends \LogicException
{
    public static function unavailable(): self
    {
        return new self('Doctrine support is not available. Make sure DoctrineBundle is installed and enabled.');
    }

    public static function persistNotSupported(): self
    {
        return new self('Cannot persist entity without Doctrine support');
    }

    public static function persistEntitiesNotSupported(): self
    {
        return new self('Cannot persist entities without Doctrine support');
    }

    public static function assertionNotSupported(): self
    {
        return new self('Cannot assert entity persistence without Doctrine support');
    }

    public static function assertionNonExistenceNotSupported(): self
    {
        return new self('Cannot assert entity non-existence without Doctrine support');
    }
}
