<?php

namespace Tourze\PHPUnitSymfonyKernelTest\Tests;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\KernelInterface;
use Tourze\PHPUnitSymfonyKernelTest\AbstractIntegrationTestCase;
use Tourze\PHPUnitSymfonyKernelTest\DoctrineTrait;
use Tourze\PHPUnitSymfonyKernelTest\Exception\DoctrineSupportException;

/**
 * Doctrine Trait 测试
 *
 * 使用具体的测试类来测试 Trait 功能
 * @internal
 */
#[CoversClass(DoctrineTrait::class)]
#[RunTestsInSeparateProcesses]
class DoctrineTraitTest extends AbstractIntegrationTestCase
{
    protected function onSetUp(): void
    {
        // 无特殊初始化需求
    }

    #[Test]
    public function 可以检查Doctrine支持状态(): void
    {
        $hasSupport = self::hasDoctrineSupport();

        $this->assertIsBool($hasSupport);
    }

    #[Test]
    public function 可以检查指定Kernel的Doctrine支持(): void
    {
        /** @var KernelInterface $kernel */
        $kernel = self::getContainer()->get('kernel');
        $hasSupport = self::hasDoctrineSupport($kernel);

        $this->assertIsBool($hasSupport);
    }

    #[Test]
    public function 有Doctrine支持时可以获取EntityManager(): void
    {
        if (!self::hasDoctrineSupport()) {
            $this->markTestSkipped('Doctrine support is not available');
        }

        $entityManager = self::getEntityManager();

        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
    }

    #[Test]
    public function 没有Doctrine支持时获取EntityManager抛出异常(): void
    {
        if (self::hasDoctrineSupport()) {
            $this->markTestSkipped('Doctrine support is available, cannot test exception case');
        }

        $this->expectException(DoctrineSupportException::class);
        $this->expectExceptionMessage('Doctrine support is not available');

        self::getEntityManager();
    }

    #[Test]
    public function 有Doctrine支持时可以清理数据库(): void
    {
        if (!self::hasDoctrineSupport()) {
            $this->markTestSkipped('Doctrine support is not available');
        }

        // cleanDatabase 不应该抛出异常
        self::cleanDatabase();

        // 验证数据库清理操作已完成（通过检查EntityManager仍然有效）
        $entityManager = self::getEntityManager();
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->assertTrue($entityManager->isOpen());
    }

    #[Test]
    public function 没有Doctrine支持时清理数据库直接返回(): void
    {
        if (self::hasDoctrineSupport()) {
            $this->markTestSkipped('Doctrine support is available, cannot test skip case');
        }

        // 没有Doctrine支持时，cleanDatabase应该直接返回，不抛出异常
        $this->expectNotToPerformAssertions();
        self::cleanDatabase();
    }

    #[Test]
    public function entityManager可以重复获取(): void
    {
        if (!self::hasDoctrineSupport()) {
            $this->markTestSkipped('Doctrine support is not available');
        }

        $em1 = self::getEntityManager();
        $em2 = self::getEntityManager();

        // 两次获取的应该是同一个实例或兼容的实例
        $this->assertInstanceOf(EntityManagerInterface::class, $em1);
        $this->assertInstanceOf(EntityManagerInterface::class, $em2);
    }

    #[Test]
    public function 数据库清理可以多次调用(): void
    {
        if (!self::hasDoctrineSupport()) {
            $this->markTestSkipped('Doctrine support is not available');
        }

        // 多次调用cleanDatabase不应该有问题
        self::cleanDatabase();
        self::cleanDatabase();
        self::cleanDatabase();

        // 验证多次清理后EntityManager仍然可用
        $entityManager = self::getEntityManager();
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->assertTrue($entityManager->isOpen());
    }

    #[Test]
    public function doctrine检查对不同的Kernel返回一致结果(): void
    {
        /** @var KernelInterface $kernel */
        $kernel = self::getContainer()->get('kernel');

        $result1 = self::hasDoctrineSupport();
        $result2 = self::hasDoctrineSupport($kernel);

        $this->assertEquals($result1, $result2);
    }

    #[Test]
    public function entityManager检查兼容性(): void
    {
        if (!self::hasDoctrineSupport()) {
            $this->markTestSkipped('Doctrine support is not available');
        }

        $entityManager = self::getEntityManager();

        // EntityManager应该是打开状态且连接正常
        $this->assertTrue($entityManager->isOpen());
    }
}
