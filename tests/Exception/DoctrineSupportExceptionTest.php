<?php

namespace Tourze\PHPUnitSymfonyKernelTest\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;
use Tourze\PHPUnitSymfonyKernelTest\Exception\DoctrineSupportException;

/**
 * Doctrine支持异常测试
 * @internal
 */
#[CoversClass(DoctrineSupportException::class)]
class DoctrineSupportExceptionTest extends AbstractExceptionTestCase
{
    #[Test]
    public function 可以创建异常实例(): void
    {
        $exception = new DoctrineSupportException('Test doctrine support');

        $this->assertInstanceOf(DoctrineSupportException::class, $exception);
        $this->assertInstanceOf(\LogicException::class, $exception);
        $this->assertEquals('Test doctrine support', $exception->getMessage());
    }

    #[Test]
    public function 可以创建不可用异常(): void
    {
        $exception = DoctrineSupportException::unavailable();

        $this->assertInstanceOf(DoctrineSupportException::class, $exception);
        $this->assertStringContainsString('Doctrine support is not available', $exception->getMessage());
    }

    #[Test]
    public function 可以创建持久化不支持异常(): void
    {
        $exception = DoctrineSupportException::persistNotSupported();

        $this->assertInstanceOf(DoctrineSupportException::class, $exception);
        $this->assertStringContainsString('Cannot persist entity', $exception->getMessage());
    }

    #[Test]
    public function 可以创建批量持久化不支持异常(): void
    {
        $exception = DoctrineSupportException::persistEntitiesNotSupported();

        $this->assertInstanceOf(DoctrineSupportException::class, $exception);
        $this->assertStringContainsString('Cannot persist entities', $exception->getMessage());
    }

    #[Test]
    public function 可以创建断言不支持异常(): void
    {
        $exception = DoctrineSupportException::assertionNotSupported();

        $this->assertInstanceOf(DoctrineSupportException::class, $exception);
        $this->assertStringContainsString('Cannot assert entity persistence', $exception->getMessage());
    }

    #[Test]
    public function 可以创建不存在断言不支持异常(): void
    {
        $exception = DoctrineSupportException::assertionNonExistenceNotSupported();

        $this->assertInstanceOf(DoctrineSupportException::class, $exception);
        $this->assertStringContainsString('Cannot assert entity non-existence', $exception->getMessage());
    }

    #[Test]
    public function 是LogicException的子类(): void
    {
        $exception = new DoctrineSupportException();

        $this->assertInstanceOf(\LogicException::class, $exception);
        $this->assertInstanceOf(\Exception::class, $exception);
        $this->assertInstanceOf(\Throwable::class, $exception);
    }

    #[Test]
    public function 静态工厂方法返回正确的异常类型(): void
    {
        $exceptions = [
            DoctrineSupportException::unavailable(),
            DoctrineSupportException::persistNotSupported(),
            DoctrineSupportException::persistEntitiesNotSupported(),
            DoctrineSupportException::assertionNotSupported(),
            DoctrineSupportException::assertionNonExistenceNotSupported(),
        ];

        foreach ($exceptions as $exception) {
            $this->assertInstanceOf(DoctrineSupportException::class, $exception);
            $this->assertNotEmpty($exception->getMessage());
        }
    }
}
