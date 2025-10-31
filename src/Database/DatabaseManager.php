<?php

namespace Tourze\PHPUnitSymfonyKernelTest\Database;

use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\Bundle\FixturesBundle\Purger\ORMPurgerFactory;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Loader;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Tourze\PHPUnitSymfonyKernelTest\DatabaseHelper;

/**
 * 数据库管理器
 *
 * 提供基于 Fixtures 的数据库管理：
 * - 自动更新 Schema
 * - 加载所有 Fixtures 数据
 * - 简单粗暴，全部加载
 *
 * @author Claude
 */
readonly class DatabaseManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContainerInterface $container,
        private DatabaseCleaner $databaseCleaner,
    ) {
    }

    /**
     * 执行数据库管理
     *
     * 等价于 doctrine:schema:update --force + doctrine:fixtures:load
     */
    public function execute(): void
    {
        try {
            $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
            $fixtures = $this->resolveFixtures();

            $hash = DatabaseHelper::computeBootstrapHash($metadata, $fixtures);

            if (!DatabaseHelper::shouldBootstrapDatabase($hash)) {
                return;
            }

            if ($this->needsSchemaUpdate($metadata)) {
                $this->updateSchema($metadata);
            }

            $this->databaseCleaner->clean();
            $this->executeFixtures($fixtures);

            DatabaseHelper::markDatabaseReady($metadata, $fixtures);
        } catch (\Throwable $e) {
            DatabaseHelper::markDatabaseFailed();

            throw $e;
        }
    }

    /**
     * 检查是否需要 Schema 更新
     *
     * @param array<int, \Doctrine\ORM\Mapping\ClassMetadata>|null $metadata
     */
    public function needsSchemaUpdate(?array $metadata = null): bool
    {
        try {
            $schemaTool = new SchemaTool($this->entityManager);
            $metadata = $metadata ?? $this->entityManager->getMetadataFactory()->getAllMetadata();

            // 确保metadata是数组格式
            if (!is_array($metadata)) {
                $metadata = iterator_to_array($metadata);
            }

            // 过滤出ClassMetadata对象
            $metadata = array_filter($metadata, function ($item) {
                return $item instanceof \Doctrine\ORM\Mapping\ClassMetadata;
            });

            // 重新索引数组，确保是list格式
            $metadata = array_values($metadata);

            $updateSql = $schemaTool->getUpdateSchemaSql($metadata);

            return count($updateSql) > 0;
        } catch (\Exception $e) {
            return true; // 如果检查失败，假设需要更新
        }
    }

    /**
     * 更新数据库 Schema
     *
     * @param array<int, \Doctrine\ORM\Mapping\ClassMetadata>|null $metadata
     */
    private function updateSchema(?array $metadata = null): void
    {
        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $metadata ?? $this->entityManager->getMetadataFactory()->getAllMetadata();

        // 使用 updateSchema 以支持增量更新
        $schemaTool->updateSchema($metadata);
    }

    /**
     * 加载 Fixtures
     *
     * 加载所有可用的 Fixtures
     *
     * @param array<int, object> $fixtures
     */
    private function executeFixtures(array $fixtures): void
    {
        if (count($fixtures) === 0) {
            return;
        }

        $factory = new ORMPurgerFactory();
        /** @var Registry $doctrine */
        $doctrine = $this->container->get('doctrine');
        $purger = $factory->createForEntityManager(
            'default',
            $this->entityManager,
        );

        $executor = new ORMExecutor($this->entityManager);
        $executor->setPurger($purger);

        $executor->execute($fixtures, false); // false = 不清理数据库
    }

    /**
     * 检查是否安装了 DoctrineFixturesBundle
     */
    private function hasFixturesBundle(): bool
    {
        return $this->container->has('doctrine.fixtures.loader');
    }

    /**
     * 创建配置的流式接口
     */
    public static function create(
        EntityManagerInterface $entityManager,
        ContainerInterface $container,
        DatabaseCleaner $databaseCleaner,
    ): self {
        return new self($entityManager, $container, $databaseCleaner);
    }

    /**
     * 加载所有需要的 Fixtures，但不执行数据库写入
     *
     * @return array<int, object>
     */
    private function resolveFixtures(): array
    {
        if (!$this->hasFixturesBundle()) {
            return [];
        }

        /** @var Loader $loader */
        $loader = $this->container->get('doctrine.fixtures.loader');
        try {
            $fixtures = $loader->getFixtures(); // 加载所有 fixtures，不过滤组

            // 调试：仅在开启 DEBUG_FIXTURES 时输出 Fixture 列表
            if (count($fixtures) > 0 && isset($_ENV['DEBUG_FIXTURES']) && $_ENV['DEBUG_FIXTURES'] !== '') {
                foreach ($fixtures as $fixture) {
                    // phpcs:ignore
                    fwrite(STDERR, '[PHPUnit-DB] Loaded Fixture: ' . $fixture::class . PHP_EOL);
                }
            }

            return is_array($fixtures) ? array_values($fixtures) : [];
        } catch (\Throwable $e) {
            // 失败时，尝试通过反射读取已注册但尚未排序的 Fixture 列表，帮助定位缺失的依赖
            if (isset($_ENV['DEBUG_FIXTURES']) && $_ENV['DEBUG_FIXTURES'] !== '') {
                try {
                    $ref = new \ReflectionClass($loader);
                    if ($ref->hasProperty('loadedFixtures')) {
                        $prop = $ref->getProperty('loadedFixtures');
                        $prop->setAccessible(true);
                        $loaded = $prop->getValue($loader);
                        if (is_array($loaded)) {
                            foreach ($loaded as $className => $instance) {
                                // phpcs:ignore
                                fwrite(STDERR, '[PHPUnit-DB] Registered (pre-order) Fixture: ' . $className . PHP_EOL);
                            }
                        }
                    }
                } catch (\Throwable) {
                    // ignore
                }
            }

            throw $e;
        }
    }
}
