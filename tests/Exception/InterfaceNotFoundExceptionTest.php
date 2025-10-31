<?php

namespace Tourze\PHPUnitSymfonyKernelTest\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;
use Tourze\PHPUnitSymfonyKernelTest\Exception\InterfaceNotFoundException;

/**
 * 接口未找到异常测试
 * @internal
 */
#[CoversClass(InterfaceNotFoundException::class)]
class InterfaceNotFoundExceptionTest extends AbstractExceptionTestCase
{
    #[Test]
    public function 可以创建异常实例(): void
    {
        $exception = new InterfaceNotFoundException('Test interface not found');

        $this->assertInstanceOf(InterfaceNotFoundException::class, $exception);
        $this->assertEquals('Test interface not found', $exception->getMessage());
    }

    #[Test]
    public function 可以使用异常码创建(): void
    {
        $exception = new InterfaceNotFoundException('Test message', 123);

        $this->assertEquals('Test message', $exception->getMessage());
        $this->assertEquals(123, $exception->getCode());
    }

    #[Test]
    public function 可以链接前一个异常(): void
    {
        $previousException = new \Exception('Previous exception');
        $exception = new InterfaceNotFoundException('Current exception', 0, $previousException);

        $this->assertEquals('Current exception', $exception->getMessage());
        $this->assertSame($previousException, $exception->getPrevious());
    }

    #[Test]
    public function 默认消息为空(): void
    {
        $exception = new InterfaceNotFoundException();

        $this->assertEquals('', $exception->getMessage());
        $this->assertEquals(0, $exception->getCode());
    }

    #[Test]
    public function 继承正确的基类(): void
    {
        $exception = new InterfaceNotFoundException();

        $this->assertInstanceOf(\Exception::class, $exception);
        $this->assertInstanceOf(\Throwable::class, $exception);
    }
}
