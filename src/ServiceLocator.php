<?php

namespace Tourze\PHPUnitSymfonyKernelTest;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Tourze\PHPUnitSymfonyKernelTest\Exception\ServiceNotFoundException;

/**
 * 服务定位器
 *
 * 提供智能的服务获取功能：
 * - 自动推断服务 ID
 * - 服务缓存
 * - 批量获取
 * - 类型安全
 *
 * @author Claude
 */
class ServiceLocator
{
    /**
     * 需要跳过类型检查的服务ID映射
     * 这些服务ID返回的实际类型和期望的类名不匹配
     */
    private const SKIP_TYPE_CHECK_SERVICES = [
        'security.token_storage',
        'security.authorization_checker',
        'security.authentication_utils',
        'Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface',
        'doctrine.orm.entity_manager',
    ];

    /**
     * @var array<string, object>
     */
    private array $serviceCache = [];

    public function __construct(
        private readonly ContainerInterface $container,
    ) {
    }

    /**
     * 获取类型安全的服务
     *
     * @template T of object
     *
     * @param class-string<T> $serviceClass
     *
     * @return T
     *
     * @throws ServiceNotFoundException
     */
    public function get(string $serviceClass): object
    {
        // 检查缓存
        if (isset($this->serviceCache[$serviceClass])) {
            /** @var T $cachedService */
            return $this->serviceCache[$serviceClass];
        }

        $service = null;

        // 尝试不同的服务 ID 格式
        $serviceIds = $this->generateServiceIds($serviceClass);

        foreach ($serviceIds as $serviceId) {
            if ($this->container->has($serviceId)) {
                $service = $this->container->get($serviceId);
                break;
            }
        }

        if (null === $service) {
            throw new ServiceNotFoundException(sprintf('Service "%s" not found. Tried IDs: %s', $serviceClass, implode(', ', $serviceIds)));
        }

        // 跳过特殊服务的类型检查
        $isSpecialService = in_array($serviceClass, self::SKIP_TYPE_CHECK_SERVICES, true);
        if (!$isSpecialService && !$service instanceof $serviceClass) {
            throw new ServiceNotFoundException(sprintf('Service "%s" is not an instance of "%s"', get_class($service), $serviceClass));
        }

        // 缓存服务实例
        $this->serviceCache[$serviceClass] = $service;

        /** @var T $service */
        return $service;
    }

    /**
     * 尝试获取服务（不抛出异常）
     *
     * @template T of object
     *
     * @param class-string<T> $serviceClass
     *
     * @return T|null
     */
    public function tryGet(string $serviceClass): ?object
    {
        try {
            return $this->get($serviceClass);
        } catch (ServiceNotFoundException) {
            return null;
        }
    }

    /**
     * 批量获取服务
     *
     * @template T of object
     *
     * @param array<class-string<T>> $serviceClasses
     *
     * @return array<class-string<T>, T>
     */
    public function getMultiple(array $serviceClasses): array
    {
        $services = [];

        foreach ($serviceClasses as $serviceClass) {
            $services[$serviceClass] = $this->get($serviceClass);
        }

        return $services;
    }

    /**
     * 清除服务缓存
     */
    public function clearCache(): void
    {
        $this->serviceCache = [];
    }

    /**
     * 检查服务是否存在
     */
    public function has(string $serviceClass): bool
    {
        $serviceIds = $this->generateServiceIds($serviceClass);

        foreach ($serviceIds as $serviceId) {
            if ($this->container->has($serviceId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 生成可能的服务 ID
     *
     * @return array<string>
     */
    private function generateServiceIds(string $serviceClass): array
    {
        $ids = [
            // 完整类名
            $serviceClass,
        ];

        // 短类名（不带命名空间）
        $lastBackslashPos = strrpos($serviceClass, '\\');
        $shortName = false !== $lastBackslashPos ? substr($serviceClass, $lastBackslashPos + 1) : $serviceClass;

        // 转换为 snake_case
        $snakeCase = $this->camelToSnake($shortName);

        // 常见的服务 ID 格式
        $ids[] = $snakeCase;
        $ids[] = 'app.' . $snakeCase;
        $ids[] = lcfirst($shortName);

        // 如果是 Repository，尝试其他格式
        if (str_ends_with($shortName, 'Repository')) {
            $entityName = substr($shortName, 0, -10);
            $ids[] = 'app.repository.' . $this->camelToSnake($entityName);
            $ids[] = $serviceClass . ':' . lcfirst($entityName);
        }

        // 如果是 Service，尝试其他格式
        if (str_ends_with($shortName, 'Service')) {
            $serviceName = substr($shortName, 0, -7);
            $ids[] = 'app.service.' . $this->camelToSnake($serviceName);
        }

        // 如果是 Controller，尝试其他格式
        if (str_ends_with($shortName, 'Controller')) {
            $controllerName = substr($shortName, 0, -10);
            $ids[] = 'app.controller.' . $this->camelToSnake($controllerName);
        }

        // 如果是 Manager，尝试其他格式
        if (str_ends_with($shortName, 'Manager')) {
            $managerName = substr($shortName, 0, -7);
            $ids[] = 'app.manager.' . $this->camelToSnake($managerName);
        }

        return array_unique($ids);
    }

    /**
     * 将 CamelCase 转换为 snake_case
     */
    private function camelToSnake(string $input): string
    {
        $result = preg_replace('/(?<!^)[A-Z]/', '_$0', $input);

        return null !== $result ? strtolower($result) : $input;
    }
}
