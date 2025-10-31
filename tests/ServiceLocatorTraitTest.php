<?php

namespace Tourze\PHPUnitSymfonyKernelTest\Tests;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Tourze\PHPUnitSymfonyKernelTest\AbstractIntegrationTestCase;
use Tourze\PHPUnitSymfonyKernelTest\Exception\ServiceNotFoundException;
use Tourze\PHPUnitSymfonyKernelTest\ServiceLocator;
use Tourze\PHPUnitSymfonyKernelTest\ServiceLocatorTrait;

/**
 * 服务定位器 Trait 测试
 *
 * 使用具体的测试类来测试 Trait 功能
 * @internal
 */
#[CoversClass(ServiceLocatorTrait::class)]
#[RunTestsInSeparateProcesses]
class ServiceLocatorTraitTest extends AbstractIntegrationTestCase
{
    protected function onSetUp(): void
    {
        // ServiceLocatorTrait 测试无需特殊初始化
    }

    #[Test]
    public function 可以获取服务定位器(): void
    {
        $serviceLocator = self::getServiceLocator();

        $this->assertInstanceOf(ServiceLocator::class, $serviceLocator);
    }

    #[Test]
    public function 服务定位器使用单例模式(): void
    {
        $serviceLocator1 = self::getServiceLocator();
        $serviceLocator2 = self::getServiceLocator();

        $this->assertSame($serviceLocator1, $serviceLocator2);
    }

    #[Test]
    public function 可以通过Trait获取类型安全的服务(): void
    {
        $entityManager = self::getService(EntityManagerInterface::class);

        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
    }

    #[Test]
    public function 可以获取容器服务(): void
    {
        // 预期会抛出服务不存在异常
        $this->expectException(ServiceNotFoundException::class);

        self::getService(ContainerInterface::class);
    }

    #[Test]
    public function 多次获取同一服务返回相同实例(): void
    {
        $service1 = self::getService(EntityManagerInterface::class);
        $service2 = self::getService(EntityManagerInterface::class);

        // 服务定位器应该缓存服务实例
        $this->assertInstanceOf(EntityManagerInterface::class, $service1);
        $this->assertInstanceOf(EntityManagerInterface::class, $service2);
    }

    #[Test]
    public function 可以清理服务定位器缓存(): void
    {
        // 首先获取一个服务以建立缓存
        $serviceLocator1 = self::getServiceLocator();

        // 清理缓存
        self::clearServiceLocatorCache();

        // 再次获取应该是新的实例
        $serviceLocator2 = self::getServiceLocator();

        $this->assertInstanceOf(ServiceLocator::class, $serviceLocator1);
        $this->assertInstanceOf(ServiceLocator::class, $serviceLocator2);
    }

    #[Test]
    public function 清理缓存后可以正常获取服务(): void
    {
        // 获取服务
        $service1 = self::getService(EntityManagerInterface::class);

        // 清理缓存
        self::clearServiceLocatorCache();

        // 再次获取服务
        $service2 = self::getService(EntityManagerInterface::class);

        $this->assertInstanceOf(EntityManagerInterface::class, $service1);
        $this->assertInstanceOf(EntityManagerInterface::class, $service2);
    }

    #[Test]
    public function 服务定位器依赖容器(): void
    {
        $serviceLocator = self::getServiceLocator();
        $container = self::getContainer();

        // 验证服务定位器确实使用了容器
        $this->assertInstanceOf(ServiceLocator::class, $serviceLocator);
        $this->assertInstanceOf(ContainerInterface::class, $container);
    }

    #[Test]
    public function trait方法都是受保护的静态方法(): void
    {
        $reflection = new \ReflectionClass(self::class);

        // 验证 getService 方法存在且为 protected static
        $this->assertTrue($reflection->hasMethod('getService'));
        $getServiceMethod = $reflection->getMethod('getService');
        $this->assertTrue($getServiceMethod->isProtected());
        $this->assertTrue($getServiceMethod->isStatic());

        // 验证 getServiceLocator 方法存在且为 protected static
        $this->assertTrue($reflection->hasMethod('getServiceLocator'));
        $getServiceLocatorMethod = $reflection->getMethod('getServiceLocator');
        $this->assertTrue($getServiceLocatorMethod->isProtected());
        $this->assertTrue($getServiceLocatorMethod->isStatic());

        // 验证 clearServiceLocatorCache 方法存在且为 protected static
        $this->assertTrue($reflection->hasMethod('clearServiceLocatorCache'));
        $clearCacheMethod = $reflection->getMethod('clearServiceLocatorCache');
        $this->assertTrue($clearCacheMethod->isProtected());
        $this->assertTrue($clearCacheMethod->isStatic());
    }

    #[Test]
    public function 多次清理缓存不会产生问题(): void
    {
        self::clearServiceLocatorCache();
        self::clearServiceLocatorCache();
        self::clearServiceLocatorCache();

        // 清理后仍能正常获取服务
        $service = self::getService(EntityManagerInterface::class);

        $this->assertInstanceOf(EntityManagerInterface::class, $service);
    }
}
