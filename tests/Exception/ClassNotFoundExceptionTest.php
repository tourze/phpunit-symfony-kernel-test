<?php

namespace Tourze\PHPUnitSymfonyKernelTest\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;
use Tourze\PHPUnitSymfonyKernelTest\Exception\ClassNotFoundException;

/**
 * ClassNotFoundException 测试
 * @internal
 */
#[CoversClass(ClassNotFoundException::class)]
class ClassNotFoundExceptionTest extends AbstractExceptionTestCase
{
    #[Test]
    public function 可以创建异常实例(): void
    {
        $exception = new ClassNotFoundException('Test class not found');

        $this->assertInstanceOf(ClassNotFoundException::class, $exception);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('Test class not found', $exception->getMessage());
    }

    #[Test]
    public function 可以使用异常码创建(): void
    {
        $exception = new ClassNotFoundException('Test message', 404);

        $this->assertEquals('Test message', $exception->getMessage());
        $this->assertEquals(404, $exception->getCode());
    }

    #[Test]
    public function 可以链接前一个异常(): void
    {
        $previous = new \Exception('prev');
        $exception = new ClassNotFoundException('current', 0, $previous);

        $this->assertEquals('current', $exception->getMessage());
        $this->assertSame($previous, $exception->getPrevious());
    }

    #[Test]
    public function 默认消息为空(): void
    {
        $exception = new ClassNotFoundException();

        $this->assertEquals('', $exception->getMessage());
        $this->assertEquals(0, $exception->getCode());
    }

    #[Test]
    public function 是RuntimeException的子类(): void
    {
        $exception = new ClassNotFoundException();

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertInstanceOf(\Exception::class, $exception);
        $this->assertInstanceOf(\Throwable::class, $exception);
    }
}
