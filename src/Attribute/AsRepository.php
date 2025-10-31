<?php

namespace Tourze\PHPUnitSymfonyKernelTest\Attribute;

#[\Attribute(flags: \Attribute::TARGET_CLASS)]
final readonly class AsRepository
{
    public function __construct(
        public string $entityClass,
    ) {
    }
}
