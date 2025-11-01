<?php

declare(strict_types=1);

namespace Tourze\PHPUnitSymfonyKernelTest;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Tourze\UserServiceContracts\UserManagerInterface;

/**
 * 用于测试的 UserProvider 适配器
 *
 * 将 UserManagerInterface 适配为 Symfony Security 的 UserProviderInterface
 * 解决 UserLoaderInterface (返回 nullable) 和 UserProviderInterface (返回 non-nullable) 的接口冲突
 *
 * 同时支持：
 * - 动态生成的实体类（用于正式测试）
 * - InMemoryUser（用于快速登录和权限测试）
 */
final class TestEntityUserProvider implements UserProviderInterface
{
    /**
     * @param class-string<UserInterface> $entityClassName 生成的实体类名（FQCN）
     */
    public function __construct(
        private readonly UserManagerInterface $userManager,
        private readonly string $entityClassName,
    ) {
    }

    /**
     * 通过标识加载用户
     *
     * 实现 UserProviderInterface::loadUserByIdentifier()
     * 找不到时抛出 UserNotFoundException
     */
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->userManager->loadUserByIdentifier($identifier);

        if (null === $user) {
            throw new UserNotFoundException(sprintf('User "%s" not found.', $identifier));
        }

        return $user;
    }

    /**
     * 刷新用户实例（从数据库重新加载）
     *
     * 实现 UserProviderInterface::refreshUser()
     *
     * 对于 InMemoryUser：直接返回原实例（无状态，无需重新加载）
     * 对于实体用户：从数据库重新加载
     */
    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$this->supportsClass($user::class)) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        // InMemoryUser 是无状态的，直接返回原实例
        if ($user instanceof InMemoryUser) {
            return $user;
        }

        // 实体用户需要从数据库重新加载
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    /**
     * 判断是否支持该用户类
     *
     * 实现 UserProviderInterface::supportsClass()
     * 使用 is_a() 以兼容 Doctrine 代理类
     *
     * 支持两种用户类型：
     * 1. 动态生成的实体类（用于正式测试）
     * 2. InMemoryUser（用于快速登录和权限测试）
     */
    public function supportsClass(string $class): bool
    {
        // 支持生成的实体类（包括其代理类）
        if (is_a($class, $this->entityClassName, true)) {
            return true;
        }

        // 支持 InMemoryUser（用于测试中的快速登录）
        if (is_a($class, InMemoryUser::class, true)) {
            return true;
        }

        return false;
    }
}
