<?php

namespace Tourze\PHPUnitSymfonyKernelTest;

use Doctrine\ORM\EntityManagerInterface;

/**
 * EntityManager 辅助工具
 *
 * 提供 EntityManager 相关的辅助功能：
 * - 自动内存管理
 * - 操作计数
 * - 性能监控
 * - 批量操作支持
 *
 * @author Claude
 */
class EntityManagerHelper
{
    /**
     * 操作计数器
     */
    private int $operationCount = 0;

    /**
     * 自动清理阈值
     */
    private int $autoClearThreshold;

    /**
     * 是否启用自动清理
     */
    private bool $autoClearEnabled;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        int $autoClearThreshold = 100,
        bool $autoClearEnabled = true,
    ) {
        $this->autoClearThreshold = $autoClearThreshold;
        $this->autoClearEnabled = $autoClearEnabled;
    }

    /**
     * 记录操作并检查是否需要清理
     *
     * @param int $count 操作数量
     */
    public function recordOperation(int $count = 1): void
    {
        $this->operationCount += $count;

        if ($this->autoClearEnabled && $this->operationCount >= $this->autoClearThreshold) {
            $this->clear();
        }
    }

    /**
     * 清理 EntityManager
     *
     * 清除所有管理的实体，释放内存
     */
    public function clear(): void
    {
        $this->entityManager->clear();
        $this->operationCount = 0;
    }

    /**
     * 重置 EntityManager
     *
     * 关闭并重新创建连接
     */
    public function reset(): void
    {
        if ($this->entityManager->isOpen()) {
            $this->entityManager->close();
        }
        $this->operationCount = 0;
    }

    /**
     * 获取当前操作计数
     */
    public function getOperationCount(): int
    {
        return $this->operationCount;
    }

    /**
     * 重置操作计数
     */
    public function resetOperationCount(): void
    {
        $this->operationCount = 0;
    }

    /**
     * 设置自动清理阈值
     */
    public function setAutoClearThreshold(int $threshold): void
    {
        $this->autoClearThreshold = $threshold;
    }

    /**
     * 获取自动清理阈值
     */
    public function getAutoClearThreshold(): int
    {
        return $this->autoClearThreshold;
    }

    /**
     * 启用自动清理
     */
    public function enableAutoClear(): void
    {
        $this->autoClearEnabled = true;
    }

    /**
     * 禁用自动清理
     */
    public function disableAutoClear(): void
    {
        $this->autoClearEnabled = false;
    }

    /**
     * 检查自动清理是否启用
     */
    public function isAutoClearEnabled(): bool
    {
        return $this->autoClearEnabled;
    }

    /**
     * 在批量操作中暂时禁用自动清理
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function withoutAutoClear(callable $callback)
    {
        $wasEnabled = $this->autoClearEnabled;
        $this->autoClearEnabled = false;

        try {
            return $callback();
        } finally {
            $this->autoClearEnabled = $wasEnabled;
        }
    }

    /**
     * 在事务中执行操作
     *
     * @template T
     *
     * @param callable(EntityManagerInterface): T $callback
     *
     * @return T
     *
     * @throws \Exception
     */
    public function transactional(callable $callback)
    {
        return $this->entityManager->wrapInTransaction(function () use ($callback) {
            return $callback($this->entityManager);
        });
    }

    /**
     * 批量持久化实体
     *
     * @param array<object> $entities
     * @param int           $batchSize 批量大小
     */
    public function batchPersist(array $entities, int $batchSize = 50): void
    {
        $count = 0;

        foreach ($entities as $entity) {
            $this->entityManager->persist($entity);
            ++$count;

            if (0 === $count % $batchSize) {
                $this->entityManager->flush();
                $this->clear();
            }
        }

        if (0 !== $count % $batchSize) {
            $this->entityManager->flush();
        }

        $this->recordOperation(count($entities));
    }

    /**
     * 批量删除实体
     *
     * @param array<object> $entities
     * @param int           $batchSize 批量大小
     */
    public function batchRemove(array $entities, int $batchSize = 50): void
    {
        $count = 0;

        foreach ($entities as $entity) {
            $this->entityManager->remove($entity);
            ++$count;

            if (0 === $count % $batchSize) {
                $this->entityManager->flush();
                $this->clear();
            }
        }

        if (0 !== $count % $batchSize) {
            $this->entityManager->flush();
        }

        $this->recordOperation(count($entities));
    }

    /**
     * 获取 EntityManager 内存使用信息
     *
     * @return array{
     *   identityMapSize: int,
     *   memoryUsage: string,
     *   peakMemoryUsage: string,
     *   operationCount: int
     * }
     */
    public function getMemoryInfo(): array
    {
        $identityMapSize = 0;

        $uow = $this->entityManager->getUnitOfWork();
        $identityMap = $uow->getIdentityMap();

        foreach ($identityMap as $entities) {
            $identityMapSize += count($entities);
        }

        return [
            'identityMapSize' => $identityMapSize,
            'memoryUsage' => $this->formatBytes(memory_get_usage(true)),
            'peakMemoryUsage' => $this->formatBytes(memory_get_peak_usage(true)),
            'operationCount' => $this->operationCount,
        ];
    }

    /**
     * 检查 EntityManager 是否打开
     */
    public function isOpen(): bool
    {
        return $this->entityManager->isOpen();
    }

    /**
     * 刷新实体状态
     *
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    public function refresh(object $entity): object
    {
        $this->entityManager->refresh($entity);

        return $entity;
    }

    /**
     * 分离实体
     */
    public function detach(object $entity): void
    {
        $this->entityManager->detach($entity);
    }

    /**
     * 合并实体
     *
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    public function merge(object $entity): object
    {
        // Doctrine ORM 3.0 中已移除 merge 方法，使用 persist 代替
        $this->entityManager->persist($entity);

        return $entity;
    }

    /**
     * 格式化字节数
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = floor(log($bytes, 1024));

        return sprintf('%.2f %s', $bytes / pow(1024, $power), $units[$power]);
    }
}
