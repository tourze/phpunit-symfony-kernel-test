<?php

namespace Tourze\PHPUnitSymfonyKernelTest;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
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
        if (false !== $envContent && preg_match('/^KERNEL_CLASS=(.+)$/m', $envContent, $matches)) {
            $kernelClass = trim($matches[1], '"\'');

            // 如果是相对类名，添加项目命名空间
            if (!str_contains($kernelClass, '\\')) {
                $composerFile = $projectDir . '/composer.json';
                if (file_exists($composerFile)) {
                    $composerContent = file_get_contents($composerFile);
                    if (false !== $composerContent) {
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
            if (str_starts_with($line, '#') || '' === $line) {
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

        // 扫描实体中使用的接口并自动生成测试实体
        $resolveTargetInterfaces = static::scanEntityInterfaces($entityMappings);

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

        // 使用匿名类扩展测试 Kernel，在构建容器时补充默认的 UserManagerInterface
        return new class(environment: $options['environment'] ?? 'test', debug: $options['debug'] ?? true, projectDir: $projectDir, appendBundles: $bundles, entityGenerator: $entityGenerator, interfaces: $resolveTargetInterfaces) extends Kernel {
            public function __construct(
                string $environment,
                bool $debug,
                string $projectDir,
                array $appendBundles,
                private readonly TestEntityGenerator $entityGenerator,
                private readonly array $interfaces,
            ) {
                parent::__construct($environment, $debug, $projectDir, $appendBundles);
            }

            protected function build(ContainerBuilder $container): void
            {
                parent::build($container);

                // 配置 Doctrine 映射生成的实体命名空间
                if ($container->hasExtension('doctrine')) {
                    // 确保 test_entities 目录存在（Doctrine 要求映射目录必须存在）
                    $testEntitiesDir = $this->getProjectDir() . '/test_entities';
                    if (!is_dir($testEntitiesDir)) {
                        mkdir($testEntitiesDir, 0o777, true);
                    }

                    $container->prependExtensionConfig('doctrine', [
                        'orm' => [
                            'mappings' => [
                                'DoctrineResolveTargetForTest' => [
                                    'type' => 'attribute',
                                    'dir' => $testEntitiesDir,
                                    'prefix' => $this->entityGenerator->getNamespace(),
                                    'is_bundle' => false,
                                ],
                            ],
                        ],
                    ]);
                }

                // 为所有扫描到的接口生成测试实体并配置映射
                $userEntityClass = null; // 记录 UserInterface 对应的实体类
                $resolveTargets = [];
                foreach ($this->interfaces as $interface) {
                    try {
                        // 生成测试实体
                        $entityClass = $this->entityGenerator->generateTestEntity($interface);

                        // 立即加载生成的类文件（确保可以被实例化）
                        $classFile = $this->getProjectDir() . '/test_entities/' . basename(str_replace('\\', '/', $entityClass)) . '.php';
                        if (file_exists($classFile)) {
                            require_once $classFile;
                        }
                        // 收集 ResolveTargetEntity 映射，稍后一次性注入 doctrine 配置
                        $resolveTargets[$interface] = $entityClass;

                        // 检查是否是 UserInterface 映射
                        if ('Symfony\Component\Security\Core\User\UserInterface' === $interface) {
                            $userEntityClass = $entityClass;
                        }
                    } catch (\Exception $e) {
                        // 记录错误但不中断测试
                        error_log(sprintf(
                            'Failed to generate test entity for interface %s: %s',
                            $interface,
                            $e->getMessage()
                        ));
                    }
                }

                // 以 doctrine 预置配置注入 resolve_target_entities，确保在元数据加载前生效
                if ($container->hasExtension('doctrine') && !empty($resolveTargets)) {
                    $container->prependExtensionConfig('doctrine', [
                        'orm' => [
                            'resolve_target_entities' => $resolveTargets,
                        ],
                    ]);
                }

                // 根据是否有 UserInterface 映射，选择合适的 UserManager
                if (null !== $userEntityClass) {
                    // 使用 TestEntityUserManager（支持 Doctrine 实体）
                    $definition = new Definition(TestEntityUserManager::class, [
                        new Reference('doctrine.orm.entity_manager'),
                        $userEntityClass,
                    ]);
                } else {
                    // 回退到 InMemoryUserManager
                    $definition = new Definition(InMemoryUserManager::class);
                }

                $definition->setPublic(true);
                $container->setDefinition(InMemoryUserManager::class, $definition);

                // 如果没有显式提供 UserManagerInterface，则注册一个基于 InMemoryUser 的默认实现
                $id = UserManagerInterface::class;
                if (!$container->has($id) && !$container->hasDefinition($id) && !$container->hasAlias($id)) {
                    $container->setAlias(UserManagerInterface::class, InMemoryUserManager::class);
                }
                $id = UserLoaderInterface::class;
                if (!$container->has($id) && !$container->hasDefinition($id) && !$container->hasAlias($id)) {
                    $container->setAlias(UserLoaderInterface::class, InMemoryUserManager::class);
                }

                // 配置 Symfony Security UserProvider（仅当检测到 UserInterface 映射时）
                if (null !== $userEntityClass && $container->hasExtension('security')) {
                    // 注册 TestEntityUserProvider 服务
                    $userProviderDefinition = new Definition(TestEntityUserProvider::class, [
                        new Reference(InMemoryUserManager::class),
                        $userEntityClass,
                    ]);
                    $userProviderDefinition->setPublic(true);
                    $container->setDefinition('test_entity_user_provider', $userProviderDefinition);

                    // 在 Security 配置中声明该 provider
                    $container->prependExtensionConfig('security', [
                        'providers' => [
                            'test_entity_user_provider' => [
                                'id' => 'test_entity_user_provider',
                            ],
                        ],
                    ]);
                }
            }
        };
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
            if (false !== $fileName) {
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
        if ($user instanceof InMemoryUser) {
            return $user;
        }

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
        /** @var TokenStorageInterface $security */
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
            /** @var TokenStorageInterface $untrackedTokenStorage */
            $untrackedTokenStorage = self::getContainer()->get('security.untracked_token_storage');
            $untrackedTokenStorage->setToken($token);

            return;
        }

        /** @var TokenStorageInterface $tokenStorage */
        $tokenStorage = self::getServiceById('security.token_storage');
        $tokenStorage->setToken($token);
    }

    /**
     * 扫描实体文件中使用的 targetEntity 接口
     *
     * @param array<string, string> $entityMappings 命名空间 => 目录路径
     * @return array<string> 接口类名列表
     */
    public static function scanEntityInterfaces(array $entityMappings): array
    {
        $interfaces = [];

        foreach ($entityMappings as $namespace => $path) {
            if (!is_dir($path)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($path)->name('*.php');

            foreach ($finder as $file) {
                $content = file_get_contents($file->getRealPath());
                if (false === $content) {
                    continue;
                }

                // 匹配所有 Doctrine 关系注解中的 targetEntity
                $patterns = [
                    // 匹配 Attribute 风格: #[ORM\ManyToOne(targetEntity: Interface::class)]
                    '/#\[ORM\\\(?:ManyToOne|OneToMany|OneToOne|ManyToMany)\([^)]*targetEntity:\s*([^:,\s\)]+)::class/i',
                    // 匹配旧注解风格: @ORM\ManyToOne(targetEntity="Interface")
                    '/@ORM\\\(?:ManyToOne|OneToMany|OneToOne|ManyToMany)\([^)]*targetEntity\s*=\s*"?([^",\s\)]+)"?/i',
                ];

                foreach ($patterns as $pattern) {
                    if (preg_match_all($pattern, $content, $matches)) {
                        foreach ($matches[1] as $className) {
                            $fullClassName = static::resolveClassName($className, $content);

                            // 只处理接口类型
                            if (null !== $fullClassName && interface_exists($fullClassName)) {
                                $interfaces[] = $fullClassName;
                            }
                        }
                    }
                }
            }
        }

        return array_unique($interfaces);
    }

    /**
     * 解析类名为完全限定名
     */
    public static function resolveClassName(string $shortName, string $fileContent): ?string
    {
        // 去掉可能的反斜杠和引号
        $shortName = trim($shortName, '\"\'');

        // 如果已经是完全限定名
        if (str_contains($shortName, '\\')) {
            $fullName = ltrim($shortName, '\\');

            return class_exists($fullName) || interface_exists($fullName) ? $fullName : null;
        }

        // 查找 use 语句
        $usePattern = '/use\s+([^;]+\\\\' . preg_quote($shortName, '/') . ')\s*;/';
        if (preg_match($usePattern, $fileContent, $matches)) {
            return $matches[1];
        }

        // 检查是否在当前命名空间
        if (preg_match('/namespace\s+([^;]+);/', $fileContent, $matches)) {
            $namespace = $matches[1];
            $possibleClassName = $namespace . '\\' . $shortName;
            if (class_exists($possibleClassName) || interface_exists($possibleClassName)) {
                return $possibleClassName;
            }
        }

        // 尝试全局命名空间
        if (class_exists($shortName) || interface_exists($shortName)) {
            return $shortName;
        }

        return null;
    }

    /**
     * 这个场景，必须使用 RunTestsInSeparateProcesses 注解的
     */
    #[Test]
    final public function testShouldHaveRunTestsInSeparateProcesses(): void
    {
        $reflection = new \ReflectionClass(static::class);
        $attributes = $reflection->getAttributes(RunTestsInSeparateProcesses::class);
        $this->assertNotEmpty($attributes, static::class . ' 这个测试用例，应使用 RunTestsInSeparateProcesses 注解');
    }
}
