<?php

namespace Tourze\PHPUnitSymfonyKernelTest;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Tourze\BundleDependency\BundleDependencyInterface;
use Tourze\PHPUnitBase\TestCaseHelper;

/**
 */
#[RunTestsInSeparateProcesses]
abstract class AbstractBundleTestCase extends AbstractIntegrationTestCase
{
    final protected static function getBundleClass(): string
    {
        $class = TestCaseHelper::extractCoverClass(self::createTestCaseReflection());
        self::assertNotNull($class, get_called_class() . ' 测试用例必须实现 CoversClass 注解');

        return $class;
    }

    protected function onSetUp(): void
    {
    }

    final protected function getShortName(): string
    {
        $parts = explode('\\', static::getBundleClass());

        return end($parts);
    }

    /**
     * 对于Bundle来说，我们使用它的自动发现会更加统一，也可以减少认为错误
     */
    #[Test]
    public function dontOverrideGetPathMethod(): void
    {
        $className = self::getBundleClass();
        $this->assertTrue(class_exists($className), "Bundle class {$className} does not exist");
        /** @var class-string $className */
        $reflection = new \ReflectionClass($className);
        $method = $reflection->getMethod('getPath');
        $this->assertTrue($method->getFileName() !== $reflection->getFileName(), "{$className} 不要实现 getPath 方法，我们应使用父类提供的自动发现和查找机制");
    }

    /**
     * 对于Bundle来说，我们使用它的自动发现会更加统一，也可以减少认为错误
     */
    #[Test]
    public function dontOverrideGetContainerExtensionMethod(): void
    {
        $className = self::getBundleClass();
        $this->assertTrue(class_exists($className), "Bundle class {$className} does not exist");
        /** @var class-string $className */
        $reflection = new \ReflectionClass($className);
        $method = $reflection->getMethod('getContainerExtension');
        $this->assertTrue($method->getFileName() !== $reflection->getFileName(), "{$className} 不要实现 getContainerExtension 方法，我们应使用父类提供的自动发现和查找机制");
    }

    #[Test]
    final public function testBundleRegisteredKeyExists(): void
    {
        $bundles = self::getContainer()->getParameter('kernel.bundles');
        $this->assertIsArray($bundles);
        $this->assertArrayHasKey($this->getShortName(), $bundles);
    }

    #[Test]
    final public function testBundleRegisteredValueExists(): void
    {
        $bundles = self::getContainer()->getParameter('kernel.bundles');
        $this->assertIsArray($bundles);
        $registerBundles = \array_flip($bundles);
        $this->assertArrayHasKey(static::getBundleClass(), $registerBundles);
    }

    /**
     * 测试，确保依赖的所有Bundle都有同时被注册
     */
    #[Test]
    final public function testDependenciesRegistered(): void
    {
        $bundleClass = static::getBundleClass();
        $this->assertTrue(class_exists($bundleClass), 'Bundle class not found');
        if (!\is_subclass_of($bundleClass, BundleDependencyInterface::class)) {
            return;
        }

        $bundles = self::getContainer()->getParameter('kernel.bundles');
        $this->assertIsArray($bundles);
        $registerBundles = \array_flip($bundles);
        foreach ($bundleClass::getBundleDependencies() as $dependencyName => $dependencyConfig) {
            $this->assertArrayHasKey($dependencyName, $registerBundles, '依赖的 ' . $dependencyName . ' 没注册，请认真检查 Bundle 类的实现');
        }
    }

    /**
     * 确保 Bundle 类是可以正常编译的
     */
    #[Test]
    final public function testBundleBuild(): void
    {
        $className = static::getBundleClass();
        $bundle = new $className();
        $this->assertInstanceOf(Bundle::class, $bundle);
        $container = new ContainerBuilder();
        $originalCount = count($container->getDefinitions());

        // 测试build方法不抛出异常
        $bundle->build($container);

        // 如果有加载 FrameworkBundle，那么这里一定会有很多个 CompilerPassConfig 的，这里暂时只判断数量
        $this->assertGreaterThan(0, count($container->getCompilerPassConfig()->getPasses()));

        // 从道理上来讲，如果有编译，服务应该是有增加的，验证容器构建完成且没有减少服务定义
        self::assertGreaterThanOrEqual($originalCount, count($container->getDefinitions()));
    }

    #[Test]
    final public function testBundleHasContainerExtension(): void
    {
        $className = static::getBundleClass();
        $bundle = new $className();
        $this->assertInstanceOf(Bundle::class, $bundle);
        // 验证 Bundle 提供了扩展
        $extension = $bundle->getContainerExtension();
        $this->assertNotNull($extension);
    }

    #[Test]
    final public function testGetBundleDependenciesIsStaticMethod(): void
    {
        $className = static::getBundleClass();
        $this->assertTrue(class_exists($className), "Bundle class {$className} does not exist");
        /** @var class-string $className */
        $reflectionClass = new \ReflectionClass($className);
        if (false === $reflectionClass->hasMethod('getBundleDependencies')) {
            parent::markTestSkipped('Bundle class does not implement getBundleDependencies method');
        }
        $method = $reflectionClass->getMethod('getBundleDependencies');

        $this->assertTrue($method->isStatic());
        $this->assertTrue($method->isPublic());
    }

    #[Test]
    final public function testBootShouldNoError(): void
    {
        $className = static::getBundleClass();
        $bundle = new $className();
        $this->assertInstanceOf(Bundle::class, $bundle);

        $bundle->boot();
    }

    #[Test]
    final public function testBundleCanBeInstantiatedMultipleTimes(): void
    {
        $className = static::getBundleClass();
        $bundle1 = new $className();
        $this->assertInstanceOf(Bundle::class, $bundle1);
        $bundle2 = new $className();
        $this->assertInstanceOf(Bundle::class, $bundle2);

        $this->assertNotSame($bundle1, $bundle2);
        $this->assertEquals($bundle1->getName(), $bundle2->getName());
    }

    #[Test]
    final public function testBundleHasCorrespondingExtension(): void
    {
        $bundleClass = TestCaseHelper::extractCoverClass(self::createTestCaseReflection());
        self::assertNotNull($bundleClass, 'Bundle class not found in CoversClass attribute');
        self::assertTrue(class_exists($bundleClass), "Bundle class {$bundleClass} does not exist");

        /** @var class-string $bundleClass */
        $bundleReflection = new \ReflectionClass($bundleClass);
        $bundleName = $bundleReflection->getShortName();

        // 移除 Bundle 后缀获取基础名称
        $baseName = str_ends_with($bundleName, 'Bundle')
            ? substr($bundleName, 0, -6)
            : $bundleName;

        $extensionClassName = $bundleReflection->getNamespaceName() . '\DependencyInjection\\' . $baseName . 'Extension';

        self::assertTrue(
            class_exists($extensionClassName),
            sprintf('Extension class %s does not exist for bundle %s', $extensionClassName, $bundleClass)
        );
    }

    /**
     * @return \ReflectionClass<object>
     */
    private static function createTestCaseReflection(): \ReflectionClass
    {
        return new \ReflectionClass(get_called_class());
    }
}
