<?php

namespace Tourze\PHPUnitSymfonyKernelTest\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Mapping\MappingException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use Tourze\PHPUnitSymfonyKernelTest\AbstractIntegrationTestCase;
use Tourze\PHPUnitSymfonyKernelTest\EntityManagerHelper;

/**
 * EntityManager辅助工具测试
 * @internal
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
class EntityManagerHelperTest extends AbstractIntegrationTestCase
{
    private EntityManagerHelper $entityManagerHelper;

    #[Test]
    public function 可以创建EntityManagerHelper实例(): void
    {
        $helper = new EntityManagerHelper($this->getEntityManager());

        $this->assertInstanceOf(EntityManagerHelper::class, $helper);
    }

    #[Test]
    public function 可以使用自定义配置创建实例(): void
    {
        $helper = new EntityManagerHelper($this->getEntityManager(), 50, false);

        $this->assertInstanceOf(EntityManagerHelper::class, $helper);
        $this->assertEquals(50, $helper->getAutoClearThreshold());
        $this->assertFalse($helper->isAutoClearEnabled());
    }

    #[Test]
    public function 可以记录操作数量(): void
    {
        $this->assertEquals(0, $this->entityManagerHelper->getOperationCount());

        $this->entityManagerHelper->recordOperation(5);

        $this->assertEquals(5, $this->entityManagerHelper->getOperationCount());
    }

    #[Test]
    public function 可以重置操作计数(): void
    {
        $this->entityManagerHelper->recordOperation(10);
        $this->assertEquals(10, $this->entityManagerHelper->getOperationCount());

        $this->entityManagerHelper->resetOperationCount();

        $this->assertEquals(0, $this->entityManagerHelper->getOperationCount());
    }

    #[Test]
    public function 可以清理EntityManager(): void
    {
        $this->entityManagerHelper->recordOperation(5);

        $this->entityManagerHelper->clear();

        $this->assertEquals(0, $this->entityManagerHelper->getOperationCount());
    }

    #[Test]
    public function 可以重置EntityManager(): void
    {
        $this->entityManagerHelper->recordOperation(10);

        $this->entityManagerHelper->reset();

        $this->assertEquals(0, $this->entityManagerHelper->getOperationCount());
    }

    #[Test]
    public function 可以检查EntityManager是否打开(): void
    {
        $isOpen = $this->entityManagerHelper->isOpen();

        $this->assertIsBool($isOpen);
        $this->assertTrue($isOpen);
    }

    #[Test]
    public function 可以设置和获取自动清理阈值(): void
    {
        $this->entityManagerHelper->setAutoClearThreshold(200);

        $this->assertEquals(200, $this->entityManagerHelper->getAutoClearThreshold());
    }

    #[Test]
    public function 可以启用和禁用自动清理(): void
    {
        $this->entityManagerHelper->enableAutoClear();
        $this->assertTrue($this->entityManagerHelper->isAutoClearEnabled());

        $this->entityManagerHelper->disableAutoClear();
        $this->assertFalse($this->entityManagerHelper->isAutoClearEnabled());
    }

    #[Test]
    public function 达到阈值时自动清理(): void
    {
        $helper = new EntityManagerHelper($this->getEntityManager(), 5, true);

        // 记录操作但未达到阈值
        $helper->recordOperation(4);
        $this->assertEquals(4, $helper->getOperationCount());

        // 达到阈值，应该自动清理
        $helper->recordOperation(1);
        $this->assertEquals(0, $helper->getOperationCount());
    }

    #[Test]
    public function 禁用自动清理时不会自动清理(): void
    {
        $helper = new EntityManagerHelper($this->getEntityManager(), 5, false);

        $helper->recordOperation(10);

        $this->assertEquals(10, $helper->getOperationCount());
    }

    #[Test]
    public function 可以在回调中暂时禁用自动清理(): void
    {
        $helper = new EntityManagerHelper($this->getEntityManager(), 5, true);

        $result = $helper->withoutAutoClear(function () use ($helper) {
            $helper->recordOperation(10);

            return $helper->getOperationCount();
        });

        $this->assertEquals(10, $result);
        $this->assertTrue($helper->isAutoClearEnabled());
    }

    #[Test]
    public function 可以执行事务操作(): void
    {
        $result = $this->entityManagerHelper->transactional(function (EntityManagerInterface $em) {
            $this->assertSame($this->getEntityManager(), $em);

            return 'transaction_result';
        });

        $this->assertEquals('transaction_result', $result);
    }

    #[Test]
    public function 可以获取内存使用信息(): void
    {
        $memoryInfo = $this->entityManagerHelper->getMemoryInfo();

        $this->assertIsArray($memoryInfo);
        $this->assertArrayHasKey('identityMapSize', $memoryInfo);
        $this->assertArrayHasKey('memoryUsage', $memoryInfo);
        $this->assertArrayHasKey('peakMemoryUsage', $memoryInfo);
        $this->assertArrayHasKey('operationCount', $memoryInfo);

        $this->assertIsInt($memoryInfo['identityMapSize']);
        $this->assertIsString($memoryInfo['memoryUsage']);
        $this->assertIsString($memoryInfo['peakMemoryUsage']);
        $this->assertIsInt($memoryInfo['operationCount']);
    }

    #[Test]
    public function 批量持久化操作(): void
    {
        $entities = [
            (object) ['id' => 1, 'name' => 'test1'],
            (object) ['id' => 2, 'name' => 'test2'],
            (object) ['id' => 3, 'name' => 'test3'],
        ];

        // 预期会抛出映射异常，因为stdClass不是Doctrine实体
        $this->expectException(MappingException::class);

        $this->entityManagerHelper->batchPersist($entities, 2);
    }

    #[Test]
    public function 批量删除操作(): void
    {
        $entities = [
            (object) ['id' => 1, 'name' => 'test1'],
            (object) ['id' => 2, 'name' => 'test2'],
        ];

        // 预期会抛出映射异常，因为stdClass不是Doctrine实体
        $this->expectException(MappingException::class);

        $this->entityManagerHelper->batchRemove($entities, 1);
    }

    #[Test]
    public function 可以刷新实体(): void
    {
        $entity = (object) ['id' => 1, 'name' => 'test'];

        // 预期会抛出映射异常，因为stdClass不是Doctrine实体
        $this->expectException(MappingException::class);

        $this->entityManagerHelper->refresh($entity);
    }

    #[Test]
    public function 可以分离实体(): void
    {
        $entity = (object) ['id' => 1, 'name' => 'test'];

        // detach方法应该不抛出异常，即使是非实体对象
        $this->entityManagerHelper->detach($entity);

        // 验证方法被调用（不会增加操作计数，因为detach不记录操作）
        $this->assertEquals(0, $this->entityManagerHelper->getOperationCount());
    }

    #[Test]
    public function 可以合并实体(): void
    {
        $entity = (object) ['id' => 1, 'name' => 'test'];

        // 预期会抛出映射异常，因为stdClass不是Doctrine实体
        $this->expectException(MappingException::class);

        $this->entityManagerHelper->merge($entity);
    }

    protected function onSetUp(): void
    {
        $this->entityManagerHelper = new EntityManagerHelper($this->getEntityManager());
    }
}
