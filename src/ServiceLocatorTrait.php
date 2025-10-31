<?php

namespace Tourze\PHPUnitSymfonyKernelTest;

/**
 * 服务定位器 Trait
 *
 * 提供类型安全的服务获取功能
 */
trait ServiceLocatorTrait
{
    private static ?ServiceLocator $serviceLocator = null;

    /**
     * 获取类型安全的服务
     *
     * @template T of object
     *
     * @param class-string<T> $serviceClass
     *
     * @return T
     */
    protected static function getService(string $serviceClass): object
    {
        return self::getServiceLocator()->get($serviceClass);
    }

    /**
     * 通过服务ID获取服务（支持Symfony服务名称）
     *
     * @param string $serviceId Symfony服务ID或类名
     *
     * @return object
     */
    protected static function getServiceById(string $serviceId): object
    {
        return static::getContainer()->get($serviceId);
    }

    /**
     * 获取服务定位器
     */
    protected static function getServiceLocator(): ServiceLocator
    {
        if (null === self::$serviceLocator) {
            self::$serviceLocator = new ServiceLocator(static::getContainer());
        }

        return self::$serviceLocator;
    }

    /**
     * 清理服务定位器缓存
     */
    protected static function clearServiceLocatorCache(): void
    {
        if (null !== self::$serviceLocator) {
            self::$serviceLocator->clearCache();
            self::$serviceLocator = null;
        }
    }
}
