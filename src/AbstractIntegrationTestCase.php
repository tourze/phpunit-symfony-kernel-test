<?php

namespace Tourze\PHPUnitSymfonyKernelTest;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;
use SymfonyTestingFramework\Kernel;
use Tourze\BundleDependency\ResolveHelper;
use Tourze\DoctrineResolveTargetEntityBundle\Testing\TestEntityGenerator;
use Tourze\PHPUnitBase\TestHelper;
use Tourze\PHPUnitSymfonyKernelTest\Exception\DoctrineSupportException;
use Tourze\UserServiceContracts\UserManagerInterface;

/**
 * 集成测试基类
 *
 * 提供集成测试的最佳实践实现，包括：
 * - 自动处理 EntityManager 关闭防止内存泄漏
 * - 类型安全的服务获取
 * - 数据库清理策略
 * - 测试隔离机制
 * - 测试数据工厂支持
 * - 查询计数和 N+1 检测
 *
 * @author Claude
 */
abstract class AbstractIntegrationTestCase extends KernelTestCase
{
    use DoctrineTrait;
    use ServiceLocatorTrait;

    private mixed $entityManager = null;

    private ?EntityManagerHelper $entityManagerHelper = null;

    /**
     * @param array<string, mixed> $options
     */
    final protected static function createKernel(array $options = []): KernelInterface
    {
        // 优先级1: 从.env.test文件读取KERNEL_CLASS（应用项目场景）
        $kernelClass = static::getKernelClassFromEnvFile();
        if (null !== $kernelClass && class_exists($kernelClass)) {
            return static::createProjectKernel($kernelClass, $options);
        }

        // 自动推断Bundle（现有逻辑）
        return static::createInferredKernel($options);
    }

    /**
     * 获取项目目录
     */
    protected static function getProjectDirectory(): ?string
    {
        $reflection = new \ReflectionClass(static::class);
        $testFile = $reflection->getFileName();
        if (false === $testFile) {
            return null;
        }

        // 检查是否在 projects/*/tests/ 目录下
        if (!preg_match('#/projects/([^/]+)/tests/#', $testFile, $matches)) {
            return null;
        }

        // 构建项目目录路径
        $projectDir = dirname($testFile);
        while (basename($projectDir) !== $matches[1] && dirname($projectDir) !== $projectDir) {
            $projectDir = dirname($projectDir);
        }

        return $projectDir;
    }

    /**
     * 从.env.test文件读取KERNEL_CLASS配置
     */
    protected static function getKernelClassFromEnvFile(): ?string
    {
        $projectDir = static::getProjectDirectory();
        if (!$projectDir) {
            return null;
        }

        $envTestFile = $projectDir . '/.env.test';
        if (!file_exists($envTestFile)) {
            return null;
        }

        // 解析.env.test文件
        $envContent = file_get_contents($envTestFile);
        if ($envContent !== false && preg_match('/^KERNEL_CLASS=(.+)$/m', $envContent, $matches)) {
            $kernelClass = trim($matches[1], '"\'');

            // 如果是相对类名，添加项目命名空间
            if (!str_contains($kernelClass, '\\')) {
                $composerFile = $projectDir . '/composer.json';
                if (file_exists($composerFile)) {
                    $composerContent = file_get_contents($composerFile);
                    if ($composerContent !== false) {
                        $composer = json_decode($composerContent, true);
                        if (isset($composer['autoload']['psr-4']) && is_array($composer['autoload']['psr-4'])) {
                            $namespace = array_key_first($composer['autoload']['psr-4']);
                            if (is_string($namespace)) {
                                $kernelClass = rtrim($namespace, '\\') . '\\' . $kernelClass;
                            }
                        }
                    }
                }
            }

            return $kernelClass;
        }

        return null;
    }

    /**
     * 加载项目的环境变量
     */
    protected static function loadProjectEnvironmentVariables(string $projectDir, string $environment): void
    {
        // 按照 Symfony 的加载顺序加载环境变量文件
        $envFiles = [
            $projectDir . '/.env',
            $projectDir . '/.env.local',
            $projectDir . '/.env.' . $environment,
            $projectDir . '/.env.' . $environment . '.local',
        ];

        foreach ($envFiles as $envFile) {
            if (file_exists($envFile)) {
                static::loadEnvFile($envFile);
            }
        }
    }

    /**
     * 加载单个环境变量文件
     */
    protected static function loadEnvFile(string $envFile): void
    {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (false === $lines) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // 跳过注释行
            if (str_starts_with($line, '#') || $line === '') {
                continue;
            }

            // 解析环境变量
            if (preg_match('/^([^=]+)=(.*)$/', $line, $matches)) {
                $key = trim($matches[1]);
                $value = trim($matches[2], '"\'');

                // 只有在环境变量不存在时才设置
                if (!isset($_ENV[$key]) && !isset($_SERVER[$key])) {
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                    putenv("{$key}={$value}");
                }
            }
        }
    }

    /**
     * 创建项目Kernel（应用开发场景）
     */
    /**
     * @param array<string, mixed> $options
     */
    protected static function createProjectKernel(string $kernelClass, array $options = []): KernelInterface
    {
        // 获取项目目录
        $projectDir = static::getProjectDirectory();

        // 在创建内核前加载项目的环境变量
        if ($projectDir) {
            static::loadProjectEnvironmentVariables($projectDir, $options['environment'] ?? 'test');
        }

        DatabaseHelper::configureCacheContext(
            $projectDir,
            $kernelClass,
            $options['environment'] ?? 'test',
            ['mode' => 'project']
        );
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = DatabaseHelper::generateUniqueDatabaseUrl();
        $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = '0.0.0.0/0';

        return new $kernelClass(
            $options['environment'] ?? 'test',
            $options['debug'] ?? true
        );
    }

    /**
     * 创建推断的Kernel（原有逻辑）
     */
    /**
     * @param array<string, mixed> $options
     */
    protected static function createInferredKernel(array $options = []): KernelInterface
    {
        // 预先加载 wechat-work-contracts 接口
        static::preloadWechatWorkContracts();

        $bundles = [
            FrameworkBundle::class => ['all' => true],
        ];

        // 自动推断当前测试类对应的 Bundle
        $bundleClass = BundleInferrer::inferBundleClass(static::class);
        if (null !== $bundleClass) {
            $bundles[$bundleClass] = ['all' => true];
        }

        // 查找所有关联Bundle，可能使用到的实体
        $entityMappings = array_merge(
            static::extractEntityMappings($bundles),
            [],
        );
        $projectDir = TestHelper::generateTempDir(static::class, array_keys($bundles), $options, $entityMappings);

        // 临时生成的实体
        $entityGenerator = new TestEntityGenerator($projectDir);
        $entityMappings[$entityGenerator->getNamespace()] = $projectDir;

        // $resolveTargetInterfaces = EntityScanner::scanInterfaces($entityMappings);

        DatabaseHelper::configureCacheContext(
            $projectDir,
            Kernel::class,
            $options['environment'] ?? 'test',
            [
                'mode' => 'inferred',
                'bundles' => array_keys($bundles),
            ]
        );
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = DatabaseHelper::generateUniqueDatabaseUrl();
        $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = '0.0.0.0/0';

        return new Kernel(
            environment: $options['environment'] ?? 'test',
            debug: $options['debug'] ?? true,
            projectDir: $projectDir,
            appendBundles: $bundles,
        );
    }

    /**
     * 提取实体映射配置
     *
     * @param array<string, class-string> $bundles
     * @return array<string, string>
     */
    protected static function extractEntityMappings(array $bundles): array
    {
        $entityMappings = [];

        $allBundles = array_replace(self::getDefaultBundles(), $bundles);

        foreach (ResolveHelper::resolveBundleDependencies($allBundles) as $bundle => $env) {
            if (!class_exists($bundle)) {
                continue;
            }

            $reflection = new \ReflectionClass($bundle);
            $fileName = $reflection->getFileName();
            if ($fileName !== false) {
                $entityPath = dirname($fileName) . '/Entity';
            } else {
                $entityPath = '';
            }
            if (is_dir($entityPath)) {
                $entityMappings[$reflection->getNamespaceName() . '\Entity'] = $entityPath;
            }
        }

        // 添加对 contracts 包的支持
        static::addContractsMappings($entityMappings);

        return $entityMappings;
    }

    /**
     * 加载默认 bundles 配置，确保实体映射包含框架级依赖
     *
     * @return array<class-string, array<string, bool>>
     */
    protected static function getDefaultBundles(): array
    {
        static $defaultBundles = null;

        if (null !== $defaultBundles) {
            return $defaultBundles;
        }

        $configPath = dirname(__DIR__, 2) . '/config/bundles.php';
        if (!is_file($configPath)) {
            $defaultBundles = [];

            return $defaultBundles;
        }

        $bundles = require $configPath;
        if ($bundles instanceof \Traversable) {
            $bundles = iterator_to_array($bundles);
        }

        $defaultBundles = is_array($bundles) ? $bundles : [];

        return $defaultBundles;
    }

    /**
     * 添加 contracts 包的映射
     *
     * @param array<string, string> $entityMappings
     */
    protected static function addContractsMappings(array &$entityMappings): void
    {
        // 尝试不同的路径来找到 wechat-work-contracts 包
        $possiblePaths = [
            dirname(__DIR__, 3) . '/packages/wechat-work-contracts/src',
            dirname(__DIR__, 4) . '/packages/wechat-work-contracts/src',
            dirname(__DIR__, 5) . '/packages/wechat-work-contracts/src',
        ];

        foreach ($possiblePaths as $contractsDir) {
            if (is_dir($contractsDir)) {
                // 手动包含必要的接口文件
                $interfaces = ['UserInterface.php', 'CorpInterface.php', 'AgentInterface.php', 'DepartmentInterface.php', 'UserLoaderInterface.php'];
                foreach ($interfaces as $interface) {
                    $filePath = $contractsDir . '/' . $interface;
                    if (file_exists($filePath)) {
                        require_once $filePath;
                    }
                }

                $entityMappings['Tourze\WechatWorkContracts'] = $contractsDir;
                break; // 找到了正确的路径就停止
            }
        }
    }

    /**
     * 预先加载 wechat-work-contracts 接口
     */
    protected static function preloadWechatWorkContracts(): void
    {
        // 如果接口已经存在，就不需要再处理
        if (interface_exists('Tourze\WechatWorkContracts\UserInterface')) {
            return;
        }

        // 尝试不同的路径来找到 wechat-work-contracts 包
        $possiblePaths = [
            dirname(__DIR__, 3) . '/packages/wechat-work-contracts/src',
            dirname(__DIR__, 4) . '/packages/wechat-work-contracts/src',
            dirname(__DIR__, 5) . '/packages/wechat-work-contracts/src',
        ];

        $loaded = false;
        foreach ($possiblePaths as $contractsDir) {
            if (is_dir($contractsDir)) {
                // 手动包含必要的接口文件
                $interfaces = ['UserInterface.php', 'CorpInterface.php', 'AgentInterface.php', 'DepartmentInterface.php', 'UserLoaderInterface.php'];
                foreach ($interfaces as $interface) {
                    $filePath = $contractsDir . '/' . $interface;
                    if (file_exists($filePath)) {
                        require_once $filePath;
                        $loaded = true;
                    }
                }
                if ($loaded) {
                    break; // 找到了正确的路径就停止
                }
            }
        }
    }

    /**
     * 获取 EntityManager 辅助工具
     */
    private function getEntityManagerHelper(): EntityManagerHelper
    {
        if (null === $this->entityManagerHelper) {
            $this->entityManagerHelper = new EntityManagerHelper(self::getEntityManager());
        }

        return $this->entityManagerHelper;
    }

    /**
     * 持久化并刷新实体
     *
     * 便捷方法，同时执行 persist 和 flush
     *
     * @param bool $refresh 是否刷新实体状态
     */
    final protected function persistAndFlush(object $entity, bool $refresh = false): object
    {
        if (!self::hasDoctrineSupport()) {
            throw DoctrineSupportException::persistNotSupported();
        }

        $em = self::getEntityManager();
        $em->persist($entity);
        $em->flush();

        // 记录操作
        $this->getEntityManagerHelper()->recordOperation();

        if ($refresh) {
            $em->refresh($entity);
        }

        return $entity;
    }

    /**
     * 批量持久化实体
     *
     * @param array<object> $entities
     */
    protected function persistEntities(array $entities): void
    {
        if (!self::hasDoctrineSupport()) {
            throw DoctrineSupportException::persistEntitiesNotSupported();
        }

        $this->getEntityManagerHelper()->batchPersist($entities);
    }

    /**
     * setUp 方法
     */
    final protected function setUp(): void
    {
        self::bootKernel();

        // 如果有 Doctrine 支持，默认清理数据库
        if (self::hasDoctrineSupport()) {
            self::cleanDatabase();
        }

        // 子类可以在这里添加自定义初始化逻辑
        $this->onSetUp();
    }

    /**
     * tearDown 方法
     *
     * 自动处理资源清理，子类重写时必须调用 parent::tearDown()
     */
    final protected function tearDown(): void
    {
        // 先让子类清理
        $this->onTearDown();

        // 重置 EntityManager 辅助工具
        $this->entityManagerHelper = null;

        // 关闭内核
        self::ensureKernelShutdown();

        parent::tearDown();

        $this->restoreExceptionHandler();
        $this->restoreErrorHandler();
    }

    private function restoreExceptionHandler(): void
    {
        while (true) {
            $previousHandler = set_exception_handler(static fn () => null);

            restore_exception_handler();

            if (null === $previousHandler) {
                break;
            }

            restore_exception_handler();
        }
    }

    private function restoreErrorHandler(): void
    {
        // 恢复错误处理器到测试开始前的状态
        // 注意：我们只恢复一次，以避免移除 PHPUnit 自己的错误处理器
        $currentHandler = set_error_handler(static fn (int $errno, string $errstr, string $errfile = '', int $errline = 0): bool => true);

        if (null !== $currentHandler) {
            // 恢复当前的处理器
            restore_error_handler();

            // 检查是否有额外的处理器需要恢复
            $previousHandler = set_error_handler(static fn (int $errno, string $errstr, string $errfile = '', int $errline = 0): bool => true);
            restore_error_handler();

            if ($previousHandler !== $currentHandler) {
                // 如果有不同的处理器，说明有额外的处理器被设置，需要恢复
                restore_error_handler();
            }
        }
    }

    /**
     * 子类可以重写此方法添加自定义的 setUp 逻辑
     */
    abstract protected function onSetUp(): void;

    /**
     * 子类可以重写此方法添加自定义的 tearDown 逻辑
     */
    protected function onTearDown(): void
    {
        // 如果有 Doctrine 支持，默认清理数据库
        // 注释掉 tearDown 中的 cleanDatabase 调用，避免重复加载 Fixtures
        // 因为下一个测试的 setUp 会再次执行清理，这里的清理是多余的
        // if (self::hasDoctrineSupport()) {
        //     $this->cleanDatabase();
        // }
    }

    /**
     * 断言实体已持久化到数据库
     */
    protected function assertEntityPersisted(object $entity, string $message = ''): void
    {
        if (!self::hasDoctrineSupport()) {
            throw DoctrineSupportException::assertionNotSupported();
        }

        $em = self::getEntityManager();
        $em->clear();

        $entityClass = get_class($entity);
        $metadata = $em->getClassMetadata($entityClass);
        $id = $metadata->getIdentifierValues($entity);

        $found = $em->find($entityClass, $id);

        $this->assertNotNull($found, '' !== $message ? $message : sprintf(
            'Entity %s with ID %s was not found in database',
            $entityClass,
            json_encode($id)
        ));
    }

    /**
     * 断言实体不存在于数据库
     *
     * @param class-string $entityClass
     * @param mixed $id
     */
    protected function assertEntityNotExists(string $entityClass, $id, string $message = ''): void
    {
        if (!self::hasDoctrineSupport()) {
            throw DoctrineSupportException::assertionNonExistenceNotSupported();
        }

        $em = self::getEntityManager();
        $em->clear();

        $found = $em->find($entityClass, $id);

        $this->assertNull($found, '' !== $message ? $message : sprintf(
            'Entity %s with ID %s should not exist in database',
            $entityClass,
            json_encode($id)
        ));
    }

    /**
     * 创建管理员用户
     */
    protected function createAdminUser(string $username = 'admin', string $password = 'password'): UserInterface
    {
        return $this->findOrCreateUser($username, $password, ['ROLE_ADMIN']);
    }

    /**
     * 查找或创建用户实体
     *
     * @param array<string> $roles
     */
    private function findOrCreateUser(string $username, string $password, array $roles): UserInterface
    {
        // 尝试查找已存在的用户
        $user = self::getService(UserManagerInterface::class)->loadUserByIdentifier($username);

        if (!$user) {
            $user = $this->createUser($username, $password, $roles);
        }

        return $user;
    }

    /**
     * 创建用户实体
     *
     * @param array<string> $roles
     */
    final protected function createUser(string $username, string $password, array $roles): UserInterface
    {
        $user = self::getService(UserManagerInterface::class)->createUser(
            userIdentifier: $username,
            password: $password,
            roles: $roles,
        );

        // 保存用户
        $this->persistAndFlush($user);
        return $user;
    }

    /**
     * 创建普通用户
     */
    protected function createNormalUser(string $username = 'user', string $password = 'password'): UserInterface
    {
        return $this->findOrCreateUser($username, $password, ['ROLE_USER']);
    }

    /**
     * 创建具有指定角色的用户
     *
     * @param array<string> $roles
     */
    protected function createUserWithRoles(array $roles, string $username = 'test', string $password = 'password'): UserInterface
    {
        return $this->findOrCreateUser($username, $password, $roles);
    }

    /**
     * 获取当前测试的认证用户
     *
     * 在集成测试中，通常通过 Security Context 获取
     */
    protected function getAuthenticatedUser(): ?UserInterface
    {
        /** @var \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface $security */
        $security = self::getServiceById('security.token_storage');
        $token = $security->getToken();

        if (null === $token) {
            return null;
        }

        $user = $token->getUser();

        return $user instanceof UserInterface ? $user : null;
    }

    /**
     * 设置当前认证用户（用于模拟登录状态）
     */
    protected function setAuthenticatedUser(UserInterface $user): void
    {
        $token = new UsernamePasswordToken(
            $user,
            'main',
            $user->getRoles()
        );

        if (self::getContainer()->has('security.untracked_token_storage')) {
            /** @var \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface $untrackedTokenStorage */
            $untrackedTokenStorage = self::getContainer()->get('security.untracked_token_storage');
            $untrackedTokenStorage->setToken($token);

            return;
        }

        /** @var \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface $tokenStorage */
        $tokenStorage = self::getServiceById('security.token_storage');
        $tokenStorage->setToken($token);
    }

    /**
     * 这个场景，必须使用 RunTestsInSeparateProcesses 注解的
     */
    #[Test]
    final public function testShouldHaveRunTestsInSeparateProcesses(): void
    {
        $reflection = new \ReflectionClass(static::class);
        $attributes = $reflection->getAttributes(\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses::class);
        $this->assertNotEmpty($attributes, static::class . ' 这个测试用例，应使用 RunTestsInSeparateProcesses 注解');
    }
}
