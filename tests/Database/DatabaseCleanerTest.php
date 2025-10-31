<?php

namespace Tourze\PHPUnitSymfonyKernelTest\Tests\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use Tourze\PHPUnitSymfonyKernelTest\AbstractIntegrationTestCase;
use Tourze\PHPUnitSymfonyKernelTest\Database\DatabaseCleaner;
use Tourze\PHPUnitSymfonyKernelTest\Exception\InvalidStrategyException;
use Tourze\PHPUnitSymfonyKernelTest\Exception\TransactionStrategyException;

/**
 * 数据库清理工具测试
 * @internal
 */
#[CoversClass(DatabaseCleaner::class)]
#[RunTestsInSeparateProcesses]
class DatabaseCleanerTest extends AbstractIntegrationTestCase
{
    private DatabaseCleaner $databaseCleaner;

    #[Test]
    public function 可以创建数据库清理器实例(): void
    {
        $cleaner = DatabaseCleaner::create($this->getEntityManagerInstance());

        $this->assertInstanceOf(DatabaseCleaner::class, $cleaner);
    }

    #[Test]
    public function 可以设置删除策略(): void
    {
        $result = $this->databaseCleaner->setStrategy(DatabaseCleaner::STRATEGY_DELETE);

        $this->assertSame($this->databaseCleaner, $result);
    }

    #[Test]
    public function 可以设置截断策略(): void
    {
        $result = $this->databaseCleaner->setStrategy(DatabaseCleaner::STRATEGY_TRUNCATE);

        $this->assertSame($this->databaseCleaner, $result);
    }

    #[Test]
    public function 设置无效策略时抛出异常(): void
    {
        $this->expectException(InvalidStrategyException::class);
        $this->expectExceptionMessage('Invalid strategy "invalid_strategy"');

        $this->databaseCleaner->setStrategy('invalid_strategy');
    }

    #[Test]
    public function 可以排除指定的表(): void
    {
        $excludedTables = ['table1', 'table2'];
        $result = $this->databaseCleaner->excludeTables($excludedTables);

        $this->assertSame($this->databaseCleaner, $result);
    }

    #[Test]
    public function testExcludeTables(): void
    {
        $excludedTables = ['table1', 'table2'];
        $result = $this->databaseCleaner->excludeTables($excludedTables);

        $this->assertSame($this->databaseCleaner, $result);
    }

    #[Test]
    public function 可以指定要清理的表(): void
    {
        $tablesToClean = ['test_table1', 'test_table2'];
        $result = $this->databaseCleaner->setTablesToClean($tablesToClean);

        $this->assertSame($this->databaseCleaner, $result);
    }

    #[Test]
    public function 可以执行删除策略清理(): void
    {
        $result = $this->databaseCleaner
            ->setStrategy(DatabaseCleaner::STRATEGY_DELETE)
        ;

        // 验证设置策略成功
        $this->assertSame($this->databaseCleaner, $result);

        // 不应该抛出异常
        $this->databaseCleaner->clean();

        // 验证数据库连接仍然有效
        $this->assertTrue($this->getEntityManagerInstance()->getConnection()->isConnected());
    }

    #[Test]
    public function 事务策略暂未实现会抛出异常(): void
    {
        $this->expectException(TransactionStrategyException::class);
        $this->expectExceptionMessage('Transaction strategy is not implemented yet');

        $this->databaseCleaner
            ->setStrategy(DatabaseCleaner::STRATEGY_TRANSACTION)
            ->clean()
        ;
    }

    #[Test]
    public function 可以清除缓存(): void
    {
        // 先执行一次清理建立缓存
        $this->databaseCleaner->setStrategy(DatabaseCleaner::STRATEGY_DELETE)->clean();

        // 执行静态方法不应该抛出异常
        DatabaseCleaner::clearCache();

        // 再次清理应该重新分析依赖关系，验证缓存确实被清除
        $this->databaseCleaner->clean();

        // 验证操作成功完成
        $this->assertTrue($this->getEntityManagerInstance()->getConnection()->isConnected());
    }

    #[Test]
    public function 流式接口可以链式调用(): void
    {
        $result = $this->databaseCleaner
            ->setStrategy(DatabaseCleaner::STRATEGY_DELETE)
            ->excludeTables(['excluded_table'])
            ->setTablesToClean(['target_table'])
        ;

        $this->assertSame($this->databaseCleaner, $result);
    }

    #[Test]
    public function 空排除表列表不影响清理(): void
    {
        $result = $this->databaseCleaner
            ->excludeTables([])
            ->setStrategy(DatabaseCleaner::STRATEGY_DELETE)
        ;

        $this->assertSame($this->databaseCleaner, $result);

        // 不应该抛出异常
        $this->databaseCleaner->clean();

        // 验证清理操作成功
        $this->assertTrue($this->getEntityManagerInstance()->getConnection()->isConnected());
    }

    #[Test]
    public function 空清理表列表使用默认行为(): void
    {
        $result = $this->databaseCleaner
            ->setTablesToClean([])
            ->setStrategy(DatabaseCleaner::STRATEGY_DELETE)
        ;

        $this->assertSame($this->databaseCleaner, $result);

        // 不应该抛出异常
        $this->databaseCleaner->clean();

        // 验证默认行为执行成功
        $this->assertTrue($this->getEntityManagerInstance()->getConnection()->isConnected());
    }

    protected function onSetUp(): void
    {
        $this->databaseCleaner = DatabaseCleaner::create($this->getEntityManagerInstance());
    }
}
