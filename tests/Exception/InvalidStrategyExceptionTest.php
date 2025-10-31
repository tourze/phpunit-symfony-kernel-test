<?php

namespace Tourze\PHPUnitSymfonyKernelTest\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;
use Tourze\PHPUnitSymfonyKernelTest\Exception\InvalidStrategyException;

/**
 * 无效策略异常测试
 * @internal
 */
#[CoversClass(InvalidStrategyException::class)]
class InvalidStrategyExceptionTest extends AbstractExceptionTestCase
{
    #[Test]
    public function 可以创建异常实例(): void
    {
        $exception = new InvalidStrategyException('Test invalid strategy');

        $this->assertInstanceOf(InvalidStrategyException::class, $exception);
        $this->assertInstanceOf(\InvalidArgumentException::class, $exception);
        $this->assertEquals('Test invalid strategy', $exception->getMessage());
    }

    #[Test]
    public function 可以使用异常码创建(): void
    {
        $exception = new InvalidStrategyException('Test message', 123);

        $this->assertEquals('Test message', $exception->getMessage());
        $this->assertEquals(123, $exception->getCode());
    }

    #[Test]
    public function 可以链接前一个异常(): void
    {
        $previousException = new \Exception('Previous exception');
        $exception = new InvalidStrategyException('Current exception', 0, $previousException);

        $this->assertEquals('Current exception', $exception->getMessage());
        $this->assertSame($previousException, $exception->getPrevious());
    }

    #[Test]
    public function 默认消息为空(): void
    {
        $exception = new InvalidStrategyException();

        $this->assertEquals('', $exception->getMessage());
        $this->assertEquals(0, $exception->getCode());
    }

    #[Test]
    public function 是InvalidArgumentException的子类(): void
    {
        $exception = new InvalidStrategyException();

        $this->assertInstanceOf(\InvalidArgumentException::class, $exception);
        $this->assertInstanceOf(\LogicException::class, $exception);
        $this->assertInstanceOf(\Exception::class, $exception);
        $this->assertInstanceOf(\Throwable::class, $exception);
    }
}
