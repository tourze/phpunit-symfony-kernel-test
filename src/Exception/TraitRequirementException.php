<?php

namespace Tourze\PHPUnitSymfonyKernelTest\Exception;

/**
 * 当 Trait 使用要求不满足时抛出的异常
 */
class TraitRequirementException extends \RuntimeException
{
    public static function methodRequired(string $traitName, string $methodName): self
    {
        return new self(sprintf('%s requires %s() method', $traitName, $methodName));
    }
}
