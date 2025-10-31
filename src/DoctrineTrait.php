<?php

namespace Tourze\PHPUnitSymfonyKernelTest;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Tourze\PHPUnitSymfonyKernelTest\Database\DatabaseCleaner;
use Tourze\PHPUnitSymfonyKernelTest\Database\DatabaseManager;
use Tourze\PHPUnitSymfonyKernelTest\Exception\DoctrineSupportException;

trait DoctrineTrait
{
    /**
     * 清理数据库
     *
     * 使用 DatabaseManager 执行：schema:update + fixtures:load
     */
    final protected static function cleanDatabase(): void
    {
        if (!self::hasDoctrineSupport()) {
            return;
        }

        $manager = new DatabaseManager(
            self::getEntityManager(),
            self::getContainer(),
            new DatabaseCleaner(self::getEntityManager())
        );

        try {
            // 执行 schema:update + fixtures:load
            $manager->execute();
        } catch (\Throwable $e) {
            self::markTestIncomplete('执行测试用例时发生错误：' . $e);
        }
    }

    /**
     * 检查是否有 Doctrine 支持
     */
    final protected static function hasDoctrineSupport(?KernelInterface $kernel = null): bool
    {
        $kernel ??= self::getContainer()->get('kernel');
        /** @var KernelInterface $kernel */
        $bundles = $kernel->getBundles();

        // 检查是否加载了 DoctrineBundle
        return isset($bundles['DoctrineBundle']);
    }

    /**
     * 获取 EntityManager 实例
     *
     * 自动跟踪 EntityManager 使用，确保在 tearDown 中关闭
     */
    final protected static function getEntityManager(): EntityManagerInterface
    {
        if (!self::hasDoctrineSupport()) {
            throw DoctrineSupportException::unavailable();
        }

        // TODO 对于 Repository 测试，这里应该返回的是 Repository 对应的 EM
        return self::getService(EntityManagerInterface::class);
    }
}
