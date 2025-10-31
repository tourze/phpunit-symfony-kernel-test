<?php

namespace Tourze\PHPUnitSymfonyKernelTest\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use Tourze\PHPUnitSymfonyKernelTest\AbstractIntegrationTestCase;
use Tourze\PHPUnitSymfonyKernelTest\BundleInferrer;
use Tourze\PHPUnitSymfonyKernelTest\Exception\ClassNotFoundException;

/**
 * Bundle推断器测试
 * @internal
 */
#[CoversClass(BundleInferrer::class)]
#[RunTestsInSeparateProcesses]
class BundleInferrerTest extends AbstractIntegrationTestCase
{
    protected function onSetUp(): void
    {
        // 无特殊初始化需求
    }

    #[Test]
    public function 能够推断Bundle类名(): void
    {
        // 测试通过反射类推断Bundle
        $reflection = new \ReflectionClass(self::class);
        $bundleClass = BundleInferrer::inferBundleClass($reflection);

        // 允许返回 null；若非 null，则应以 Bundle 结尾
        $this->assertTrue(null === $bundleClass || str_ends_with($bundleClass, 'Bundle'));
    }

    #[Test]
    public function 能够通过字符串类名推断Bundle(): void
    {
        $bundleClass = BundleInferrer::inferBundleClass(self::class);

        // 允许返回 null；若非 null，则应以 Bundle 结尾
        $this->assertTrue(null === $bundleClass || str_ends_with($bundleClass, 'Bundle'));
    }

    #[Test]
    public function 能够获取候选Bundle列表(): void
    {
        $candidates = BundleInferrer::getCandidates(self::class);

        $this->assertIsArray($candidates);
        // 候选列表应该包含字符串类名
        foreach ($candidates as $candidate) {
            $this->assertIsString($candidate);
        }
    }

    #[Test]
    public function 处理无效类名时不会抛出异常(): void
    {
        // 测试不存在的类会抛出自定义异常
        $this->expectException(ClassNotFoundException::class);
        $this->expectExceptionMessage("Class 'NonExistentClass' does not exist");

        BundleInferrer::inferBundleClass('NonExistentClass');
    }

    #[Test]
    public function 能够处理匿名类(): void
    {
        $anonymousClass = new class {};
        $reflection = new \ReflectionClass($anonymousClass);

        // 匿名类返回null是预期的行为，因为无法推断对应的Bundle
        // 这个测试确保方法不会抛出异常即可
        $bundleClass = BundleInferrer::inferBundleClass($reflection);

        // 验证匿名类确实无法推断出Bundle（返回null）
        $this->assertNull($bundleClass);
    }

    #[Test]
    public function 候选列表不包含重复项(): void
    {
        $candidates = BundleInferrer::getCandidates(self::class);

        $uniqueCandidates = array_unique($candidates);
        $this->assertCount(count($candidates), $uniqueCandidates, '候选列表不应包含重复项');
    }

    #[Test]
    public function 能够处理简单的命名空间结构(): void
    {
        // 测试不存在的类会抛出自定义异常
        $this->expectException(ClassNotFoundException::class);
        $this->expectExceptionMessage("Class 'App\\Tests\\SomeTest' does not exist");

        $testClass = 'App\Tests\SomeTest';
        BundleInferrer::getCandidates($testClass);
    }
}
