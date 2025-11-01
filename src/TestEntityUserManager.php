<?php

declare(strict_types=1);

namespace Tourze\PHPUnitSymfonyKernelTest;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Tourze\UserServiceContracts\UserManagerInterface;

/**
 * 基于 Doctrine 实体的测试用户管理器
 *
 * 用于支持 ResolveTargetEntity 场景：
 * - 当实体关联使用 targetEntity: UserInterface::class 时
 * - 通过 TestEntityGenerator 生成对应的测试实体类
 * - 本管理器创建并持久化该生成的实体实例
 */
final class TestEntityUserManager implements UserManagerInterface
{
    /**
     * @var array<string, UserInterface> 已创建用户的内存缓存
     */
    private array $users = [];

    /**
     * @param class-string<UserInterface> $entityClassName 生成的实体类名（FQCN）
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $entityClassName,
    ) {
    }

    /**
     * 通过标识加载用户；先查内存缓存，再查数据库
     *
     * 实现 UserLoaderInterface::loadUserByIdentifier()
     * 返回 nullable（符合 Doctrine UserLoader 契约）
     */
    public function loadUserByIdentifier(string $identifier): ?UserInterface
    {
        // 先查内存缓存
        if (isset($this->users[$identifier])) {
            return $this->users[$identifier];
        }

        // 查询数据库
        $user = $this->entityManager->getRepository($this->entityClassName)
            ->findOneBy(['userIdentifier' => $identifier]);

        if ($user instanceof UserInterface) {
            $this->users[$identifier] = $user;

            return $user;
        }

        return null;
    }

    /**
     * 创建用户实体实例
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
        // 动态实例化生成的实体类
        $user = new ($this->entityClassName)();

        // 使用 setter 设置属性（生成的实体由 InterfaceAnalyzer 推断出这些方法）
        if (method_exists($user, 'setUserIdentifier')) {
            $user->setUserIdentifier($userIdentifier);
        }

        if (null !== $nickName && method_exists($user, 'setNickName')) {
            $user->setNickName($nickName);
        }

        if (null !== $avatarUrl && method_exists($user, 'setAvatarUrl')) {
            $user->setAvatarUrl($avatarUrl);
        }

        if (null !== $password && method_exists($user, 'setPassword')) {
            $user->setPassword($password);
        }

        if ([] !== $roles && method_exists($user, 'setRoles')) {
            $user->setRoles($roles);
        }

        // 缓存到内存
        $this->users[$userIdentifier] = $user;

        return $user;
    }

    /**
     * 保存用户到数据库
     */
    public function saveUser(UserInterface $user): void
    {
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // 同步到内存缓存
        $this->users[$user->getUserIdentifier()] = $user;
    }

    /**
     * 搜索用户（基于数据库查询）
     *
     * @return array<array{id: mixed, text: string}>
     */
    public function searchUsers(string $query, int $limit = 20): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('u')
            ->from($this->entityClassName, 'u')
            ->setMaxResults($limit);

        if ('' !== $query) {
            $qb->where('u.userIdentifier LIKE :query')
                ->setParameter('query', '%'.$query.'%');
        }

        $users = $qb->getQuery()->getResult();
        $result = [];

        foreach ($users as $user) {
            if ($user instanceof UserInterface) {
                $id = method_exists($user, 'getId') ? $user->getId() : $user->getUserIdentifier();
                $result[] = [
                    'id' => $id,
                    'text' => $user->getUserIdentifier(),
                ];
            }
        }

        return $result;
    }
}
