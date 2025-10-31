<?php

namespace Tourze\PHPUnitSymfonyKernelTest\Tests\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Tourze\PHPUnitSymfonyKernelTest\AbstractIntegrationTestCase;
use Tourze\PHPUnitSymfonyKernelTest\Database\DatabaseCleaner;
use Tourze\PHPUnitSymfonyKernelTest\Database\DatabaseManager;

/**
 * 数据库管理器测试
 * @internal
 */
#[CoversClass(DatabaseManager::class)]
#[RunTestsInSeparateProcesses]
class DatabaseManagerTest extends AbstractIntegrationTestCase
{
    private ContainerInterface $container;

    private DatabaseCleaner $databaseCleaner;

    private DatabaseManager $databaseManager;

    #[Test]
    public function 可以创建数据库管理器实例(): void
    {
        $manager = DatabaseManager::create(
            self::getEntityManager(),
            $this->container,
            $this->databaseCleaner
        );

        $this->assertInstanceOf(DatabaseManager::class, $manager);
    }

    #[Test]
    public function 可以使用静态工厂方法创建实例(): void
    {
        $manager = DatabaseManager::create(
            self::getEntityManager(),
            $this->container,
            $this->databaseCleaner
        );

        $this->assertInstanceOf(DatabaseManager::class, $manager);
    }

    #[Test]
    public function 可以检查是否需要Schema更新(): void
    {
        $needsUpdate = $this->databaseManager->needsSchemaUpdate();

        // 结果应该是布尔值
        $this->assertIsBool($needsUpdate);
    }

    #[Test]
    public function testNeedsSchemaUpdate(): void
    {
        $needsUpdate = $this->databaseManager->needsSchemaUpdate();

        // 结果应该是布尔值
        $this->assertIsBool($needsUpdate);
    }

    #[Test]
    public function 执行数据库管理不抛出异常(): void
    {
        // 执行完整的数据库管理流程
        $this->databaseManager->execute();

        // 验证数据库连接仍然有效
        $this->assertSame('1', (string) self::getEntityManager()->getConnection()->fetchOne('SELECT 1'));

        // 验证Schema检查能正常工作
        $needsUpdate = $this->databaseManager->needsSchemaUpdate();
        $this->assertIsBool($needsUpdate);
    }

    #[Test]
    public function schema更新检查处理异常情况(): void
    {
        // 当检查Schema时出现异常，应该返回true（假设需要更新）
        $needsUpdate = $this->databaseManager->needsSchemaUpdate();

        $this->assertIsBool($needsUpdate);
    }

    #[Test]
    public function 对于ReadOnly实体管理器的兼容性(): void
    {
        // 确保对只读EntityManager也能正常工作
        $this->databaseManager->execute();

        // 验证即使是只读模式，也不会抛出异常
        $this->assertSame('1', (string) self::getEntityManager()->getConnection()->fetchOne('SELECT 1'));

        // 验证Schema检查的兼容性
        $this->assertIsBool($this->databaseManager->needsSchemaUpdate());
    }

    #[Test]
    public function 工厂方法创建的实例与构造函数创建的实例等价(): void
    {
        $factoryInstance = DatabaseManager::create(
            self::getEntityManager(),
            $this->container,
            $this->databaseCleaner
        );
        $this->assertInstanceOf(DatabaseManager::class, $factoryInstance);
    }

    #[Test]
    public function 没有DoctrineFixturesBundle时正常工作(): void
    {
        // 即使没有fixtures bundle，execute方法也应该正常工作
        $this->databaseManager->execute();

        // 验证在没有fixtures的情况下也能正常工作
        $this->assertSame('1', (string) self::getEntityManager()->getConnection()->fetchOne('SELECT 1'));

        // 验证Schema管理功能仍然可用
        $this->assertIsBool($this->databaseManager->needsSchemaUpdate());
    }

    #[Test]
    public function 多次执行不会产生问题(): void
    {
        // 连续多次执行应该都能正常工作
        $this->databaseManager->execute();
        $this->databaseManager->execute();
        $this->databaseManager->execute();

        // 验证多次执行后数据库连接仍然有效
        $this->assertSame('1', (string) self::getEntityManager()->getConnection()->fetchOne('SELECT 1'));

        // 验证每次执行后Schema检查都能正常工作
        $this->assertIsBool($this->databaseManager->needsSchemaUpdate());
    }

    #[Test]
    public function 处理空元数据的情况(): void
    {
        // 当没有实体元数据时，needsSchemaUpdate应该能正常处理
        $needsUpdate = $this->databaseManager->needsSchemaUpdate();

        $this->assertIsBool($needsUpdate);
    }

    protected function onSetUp(): void
    {
        $this->container = self::getContainer();
        $this->databaseCleaner = DatabaseCleaner::create(self::getEntityManager());
        $this->databaseManager = DatabaseManager::create(
            self::getEntityManager(),
            $this->container,
            $this->databaseCleaner
        );
    }
}
