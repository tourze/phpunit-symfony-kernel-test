<?php

namespace Tourze\PHPUnitSymfonyKernelTest\Database;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;
use Tourze\PHPUnitSymfonyKernelTest\Exception\CircularDependencyException;
use Tourze\PHPUnitSymfonyKernelTest\Exception\InvalidStrategyException;
use Tourze\PHPUnitSymfonyKernelTest\Exception\TransactionStrategyException;
use Tourze\PHPUnitSymfonyKernelTest\Exception\UnsupportedPlatformException;

/**
 * 数据库清理工具
 *
 * 提供智能的数据库清理功能：
 * - 自动分析表依赖关系
 * - 支持多种清理策略
 * - 处理外键约束
 * - 缓存表结构信息提升性能
 *
 * @author Claude
 */
class DatabaseCleaner
{
    /**
     * 清理策略：使用 DELETE 语句（安全，处理外键约束）
     */
    public const STRATEGY_DELETE = 'delete';

    /**
     * 清理策略：使用 TRUNCATE 语句（快速，需要禁用外键检查）
     */
    public const STRATEGY_TRUNCATE = 'truncate';

    /**
     * 清理策略：使用事务回滚（最快，但不适合所有场景）
     */
    public const STRATEGY_TRANSACTION = 'transaction';

    /**
     * @var array<string, array<string>>
     */
    private static array $tableOrderCache = [];

    private string $strategy = self::STRATEGY_DELETE;

    /**
     * @var array<string>
     */
    private array $excludedTables = [];

    /**
     * @var array<string>
     */
    private array $tablesToClean = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * 工厂方法：创建清理器
     */
    public static function create(EntityManagerInterface $entityManager): self
    {
        return new self($entityManager);
    }

    /**
     * 设置清理策略
     *
     * @return $this
     */
    public function setStrategy(string $strategy): self
    {
        if (!in_array($strategy, [self::STRATEGY_DELETE, self::STRATEGY_TRUNCATE, self::STRATEGY_TRANSACTION], true)) {
            throw new InvalidStrategyException(sprintf('Invalid strategy "%s"', $strategy));
        }

        $this->strategy = $strategy;

        return $this;
    }

    /**
     * 排除特定表不进行清理
     *
     * @param array<string> $tables
     *
     * @return $this
     */
    public function excludeTables(array $tables): self
    {
        $this->excludedTables = $tables;

        return $this;
    }

    /**
     * 指定要清理的表（如果不指定，则清理所有实体对应的表）
     *
     * @param array<string> $tables
     *
     * @return $this
     */
    public function setTablesToClean(array $tables): self
    {
        $this->tablesToClean = $tables;

        return $this;
    }

    /**
     * 执行数据库清理
     */
    public function clean(): void
    {
        match ($this->strategy) {
            self::STRATEGY_DELETE => $this->cleanWithDelete(),
            self::STRATEGY_TRUNCATE => $this->cleanWithTruncate(),
            self::STRATEGY_TRANSACTION => $this->cleanWithTransaction(),
            default => throw new InvalidStrategyException(sprintf('Unknown strategy "%s"', $this->strategy)),
        };
    }

    /**
     * 使用 DELETE 策略清理
     */
    private function cleanWithDelete(): void
    {
        $connection = $this->entityManager->getConnection();
        $tables = $this->getTablesInOrder();

        foreach ($tables as $table) {
            if (in_array($table, $this->excludedTables, true)) {
                continue;
            }

            try {
                $connection->executeStatement(sprintf('DELETE FROM %s', $connection->quoteSingleIdentifier($table)));
            } catch (Exception $e) {
                // 忽略表不存在的错误和只读数据库错误
                if (!str_contains($e->getMessage(), 'does not exist')
                    && !str_contains($e->getMessage(), 'no such table')
                    && !str_contains($e->getMessage(), 'readonly database')) {
                    throw $e;
                }
            }
        }
    }

    /**
     * 使用 TRUNCATE 策略清理
     */
    private function cleanWithTruncate(): void
    {
        $connection = $this->entityManager->getConnection();
        $platform = $connection->getDatabasePlatform();
        $tables = $this->getTablesInOrder();

        // 禁用外键检查
        $this->setForeignKeyChecks(false);

        try {
            foreach ($tables as $table) {
                if (in_array($table, $this->excludedTables, true)) {
                    continue;
                }

                try {
                    $sql = $platform->getTruncateTableSQL($connection->quoteSingleIdentifier($table));
                    $connection->executeStatement($sql);
                } catch (Exception $e) {
                    // 忽略表不存在的错误
                    if (!str_contains($e->getMessage(), 'does not exist')
                        && !str_contains($e->getMessage(), 'no such table')) {
                        throw $e;
                    }
                }
            }
        } finally {
            // 恢复外键检查
            $this->setForeignKeyChecks(true);
        }
    }

    /**
     * 使用事务策略清理
     *
     * 注意：这种策略不会真正清理数据，而是通过事务回滚来隔离测试
     */
    private function cleanWithTransaction(): void
    {
        throw new TransactionStrategyException('Transaction strategy is not implemented yet');
    }

    /**
     * 获取按依赖顺序排列的表名
     *
     * @return array<string>
     */
    private function getTablesInOrder(): array
    {
        $cacheKey = $this->getCacheKey();

        if (!isset(self::$tableOrderCache[$cacheKey])) {
            self::$tableOrderCache[$cacheKey] = $this->analyzeDependencies();
        }

        return self::$tableOrderCache[$cacheKey];
    }

    /**
     * 分析表依赖关系
     *
     * @return array<string>
     */
    private function analyzeDependencies(): array
    {
        if (count($this->tablesToClean) > 0) {
            return array_reverse($this->tablesToClean);
        }

        $metadatas = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $tablesAndDependencies = $this->extractTablesAndDependencies($metadatas);

        return $this->topologicalSort($tablesAndDependencies['tables'], $tablesAndDependencies['dependencies']);
    }

    /**
     * 从元数据中提取表名和依赖关系
     *
     * @param array<mixed> $metadatas
     * @return array{tables: array<string>, dependencies: array<string, array<string>>}
     */
    private function extractTablesAndDependencies(array $metadatas): array
    {
        $tables = [];
        $dependencies = [];

        foreach ($metadatas as $metadata) {
            if ($this->shouldSkipMetadata($metadata)) {
                continue;
            }

            $tableName = $metadata->getTableName();
            $tables[] = $tableName;
            $dependencies[$tableName] = $this->extractTableDependencies($metadata);
        }

        return ['tables' => $tables, 'dependencies' => $dependencies];
    }

    /**
     * @param mixed $metadata
     */
    private function shouldSkipMetadata($metadata): bool
    {
        return $metadata->isMappedSuperclass || $metadata->isEmbeddedClass;
    }

    /**
     * 提取单个表的依赖关系
     *
     * @param mixed $metadata
     * @return array<string>
     */
    private function extractTableDependencies($metadata): array
    {
        $dependencies = [];

        foreach ($metadata->getAssociationMappings() as $association) {
            $dependency = $this->processSingleAssociation($association);
            if (null !== $dependency) {
                $dependencies[] = $dependency;
            }
        }

        return $dependencies;
    }

    private function processSingleAssociation(AssociationMapping $association): ?string
    {
        // 只处理拥有方的关联关系
        if (!$association instanceof ToOneOwningSideMapping) {
            return null;
        }

        // 跳过自引用关系以避免循环依赖
        if ($association->sourceEntity === $association->targetEntity) {
            return null;
        }

        $targetMetadata = $this->entityManager->getClassMetadata($association->targetEntity);

        return $targetMetadata->getTableName();
    }

    /**
     * 拓扑排序
     *
     * @param array<string>                $nodes
     * @param array<string, array<string>> $dependencies
     *
     * @return array<string>
     */
    private function topologicalSort(array $nodes, array $dependencies): array
    {
        $sorted = [];
        $visited = [];
        $visiting = [];

        $visit = function (string $node) use (&$visit, &$sorted, &$visited, &$visiting, $dependencies): void {
            if (isset($visited[$node])) {
                return;
            }

            if (isset($visiting[$node])) {
                throw new CircularDependencyException(sprintf('Circular dependency detected involving table "%s"', $node));
            }

            $visiting[$node] = true;

            if (isset($dependencies[$node])) {
                foreach ($dependencies[$node] as $dependency) {
                    $visit($dependency);
                }
            }

            unset($visiting[$node]);
            $visited[$node] = true;
            $sorted[] = $node;
        };

        foreach ($nodes as $node) {
            $visit($node);
        }

        return $sorted;
    }

    /**
     * 设置外键检查
     */
    private function setForeignKeyChecks(bool $enabled): void
    {
        $connection = $this->entityManager->getConnection();
        $platform = $connection->getDatabasePlatform();

        switch (true) {
            case $platform instanceof MySQLPlatform:
                $connection->executeStatement(sprintf('SET FOREIGN_KEY_CHECKS = %d', $enabled ? 1 : 0));
                break;

            case $platform instanceof SQLitePlatform:
                $connection->executeStatement(sprintf('PRAGMA foreign_keys = %s', $enabled ? 'ON' : 'OFF'));
                break;

            default:
                throw new UnsupportedPlatformException(sprintf('Unsupported platform "%s" for foreign key manipulation', get_class($platform)));
        }
    }

    /**
     * 获取缓存键
     */
    private function getCacheKey(): string
    {
        return md5(serialize([
            'strategy' => $this->strategy,
            'excluded' => $this->excludedTables,
            'tables' => $this->tablesToClean,
        ]));
    }

    /**
     * 清除缓存
     */
    public static function clearCache(): void
    {
        self::$tableOrderCache = [];
    }
}
