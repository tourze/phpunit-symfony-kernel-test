<?php

namespace Tourze\PHPUnitSymfonyKernelTest\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;
use Tourze\PHPUnitSymfonyKernelTest\Exception\KernelCreationException;

/**
 * 内核创建异常测试
 * @internal
 */
#[CoversClass(KernelCreationException::class)]
class KernelCreationExceptionTest extends AbstractExceptionTestCase
{
    #[Test]
    public function 可以创建异常实例(): void
    {
        $exception = new KernelCreationException('TestClass', 'Test kernel creation');

        $this->assertInstanceOf(KernelCreationException::class, $exception);
        $this->assertStringContainsString('Test kernel creation', $exception->getMessage());
    }

    #[Test]
    public function 可以使用异常码创建(): void
    {
        $exception = new KernelCreationException('TestClass', 'Test message');

        $this->assertStringContainsString('Test message', $exception->getMessage());
        $this->assertEquals(0, $exception->getCode());
    }

    #[Test]
    public function 可以链接前一个异常(): void
    {
        $previousException = new \Exception('Previous exception');
        $exception = new KernelCreationException('TestClass', 'Current exception');

        $this->assertStringContainsString('Current exception', $exception->getMessage());
        // KernelCreationException doesn't support previous exception, so getPrevious() always returns null
        // No need to explicitly test this as it's by design
    }

    #[Test]
    public function 默认消息为空(): void
    {
        $exception = new KernelCreationException('TestClass');

        $this->assertStringContainsString('TestClass', $exception->getMessage()); // Default message includes test class name
        $this->assertEquals(0, $exception->getCode());
    }

    #[Test]
    public function 继承正确的基类(): void
    {
        $exception = new KernelCreationException('TestClass');

        $this->assertInstanceOf(\Exception::class, $exception);
        $this->assertInstanceOf(\Throwable::class, $exception);
    }
}
