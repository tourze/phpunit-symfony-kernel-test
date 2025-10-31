<?php

declare(strict_types=1);

namespace Tourze\PHPUnitSymfonyKernelTest;

use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Tourze\UserServiceContracts\UserManagerInterface;

/**
 * 基于内存的用户管理器（测试缺省实现）
 *
 * - 用户对象使用 Symfony 的 InMemoryUser
 * - 仅在容器缺少 UserManagerInterface 服务时作为回退使用
 */
final class InMemoryUserManager implements UserManagerInterface
{
    /**
     * @var array<string, InMemoryUser> 以 userIdentifier 作为键
     */
    private array $users = [];

    /**
     * 通过标识加载用户；如果不存在，返回 null
     */
    public function loadUserByIdentifier(string $identifier): ?UserInterface
    {
        return $this->users[$identifier] ?? null;
    }

    /**
     * 创建用户（基于 InMemoryUser）
     *
     * @param array<string> $roles
     */
    public function createUser(
        string $userIdentifier,
        ?string $nickName = null,
        ?string $avatarUrl = null,
        ?string $password = null,
        array $roles = [],
    ): UserInterface {
        // InMemoryUser 不关心昵称、头像；密码仅占位
        $user = new InMemoryUser($userIdentifier, (string) ($password ?? ''), $roles);
        $this->users[$userIdentifier] = $user;

        return $user;
    }

    /**
     * 保存用户（对内存实现而言为幂等写入）
     */
    public function saveUser(UserInterface $user): void
    {
        $this->users[$user->getUserIdentifier()] = $user instanceof InMemoryUser
            ? $user
            : new InMemoryUser($user->getUserIdentifier(), '', $user->getRoles());
    }

    /**
     * 简单的内存搜索实现：按标识包含关系模糊匹配
     *
     * @return array<array{id: string, text: string}>
     */
    public function searchUsers(string $query, int $limit = 20): array
    {
        $result = [];
        $needle = mb_strtolower($query);

        foreach ($this->users as $id => $user) {
            if ('' === $query || str_contains(mb_strtolower($id), $needle)) {
                $result[] = ['id' => $id, 'text' => $id];
                if (count($result) >= $limit) {
                    break;
                }
            }
        }

        return $result;
    }
}
