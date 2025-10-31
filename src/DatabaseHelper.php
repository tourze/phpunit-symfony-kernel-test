<?php

namespace Tourze\PHPUnitSymfonyKernelTest;

class DatabaseHelper
{
    /**
     * @var array<string>
     */
    private static array $generatedDatabases = [];

    private static bool $shutdownRegistered = false;

    /**
     * @var array{project_dir: ?string, kernel_class: ?string, environment: string, extra: array<string, mixed>}
     */
    private static array $cacheContext = [
        'project_dir' => null,
        'kernel_class' => null,
        'environment' => 'test',
        'extra' => [],
    ];

    private static ?string $cacheKey = null;

    private static ?string $templatePath = null;

    private static ?string $metadataPath = null;

    private static ?string $lockFile = null;

    /** @var resource|null */
    private static $lockHandle = null;

    private static bool $lockOwned = false;

    private static bool $pendingTemplateBuild = false;

    private static ?string $templateHash = null;

    private static ?string $currentDatabaseFile = null;

    private static ?string $currentHash = null;

    private static bool $cacheDisabled = false;

    /**
     * 配置缓存上下文信息，确保哈希计算具有稳定输入
     *
     * @param array<string, mixed> $extra
     */
    public static function configureCacheContext(?string $projectDir, ?string $kernelClass, string $environment = 'test', array $extra = []): void
    {
        self::$cacheContext = [
            'project_dir' => $projectDir ? rtrim($projectDir, DIRECTORY_SEPARATOR) : null,
            'kernel_class' => $kernelClass,
            'environment' => $environment,
            'extra' => $extra,
        ];
    }

    /**
     * 禁用数据库模板缓存（主要用于调试场景）
     */
    public static function disableCache(bool $disabled = true): void
    {
        self::$cacheDisabled = $disabled;
    }

    /**
     * 使用内存数据库，确保每个测试完全隔离
     *
     * @return string
     */
    public static function generateUniqueDatabaseUrl(): string
    {
        self::registerShutdownCleanup();

        $dbFile = self::createDatabaseFilePath();
        self::$currentDatabaseFile = $dbFile;

        if (self::isCacheDisabled()) {
            self::$generatedDatabases[] = $dbFile;

            return self::buildSqliteUrl($dbFile);
        }

        self::initializeCacheMetadata();

        if (null !== self::$templateHash && is_file(self::$templatePath ?? '')) {
            // 模板可用，直接复制生成新的数据库文件
            if (self::$templatePath !== null) {
                self::copyDatabaseFiles(self::$templatePath, $dbFile);
            }
            self::$pendingTemplateBuild = false;
            self::releaseLock();
        } else {
            // 模板不可用，等待后续构建流程
            self::$pendingTemplateBuild = true;
        }

        self::$generatedDatabases[] = $dbFile;

        return self::buildSqliteUrl($dbFile);
    }

    /**
     * 判断当前数据库是否需要执行 schema 更新 + fixtures 加载
     *
     * @param string $currentHash 根据元数据和 fixtures 计算得到的指纹
     */
    public static function shouldBootstrapDatabase(string $currentHash): bool
    {
        if (self::isCacheDisabled()) {
            return true;
        }

        self::$currentHash = $currentHash;

        if (self::$pendingTemplateBuild) {
            // 首次构建直接进入初始化流程
            return true;
        }

        if (null === self::$templateHash) {
            // 模板缺失，立即进入构建流程
            self::ensureLockAcquired();
            self::$pendingTemplateBuild = true;

            return true;
        }

        if (self::$templateHash !== $currentHash) {
            // 哈希不一致，说明 schema 或 fixtures 发生变化，需要重新初始化
            self::ensureLockAcquired();
            self::removeIfExists(self::$templatePath ?? '');
            self::removeIfExists(self::$metadataPath ?? '');
            self::$templateHash = null;
            self::$pendingTemplateBuild = true;

            return true;
        }

        return false;
    }

    /**
     * 初始化流程成功后，写入模板并释放锁
     *
     * @param array<int, object> $metadata
     * @param array<int, object> $fixtures
     */
    public static function markDatabaseReady(array $metadata, array $fixtures): void
    {
        if (self::isCacheDisabled() || !self::$pendingTemplateBuild) {
            self::releaseLock();

            return;
        }

        self::ensureLockAcquired();

        if (null === self::$currentDatabaseFile || null === self::$templatePath) {
            self::releaseLock();

            return;
        }

        self::copyDatabaseFiles(self::$currentDatabaseFile, self::$templatePath);

        $hash = self::computeBootstrapHash($metadata, $fixtures);
        $metaPayload = [
            'hash' => $hash,
            'generated_at' => time(),
        ];
        if (null !== self::$metadataPath) {
            file_put_contents(self::$metadataPath, json_encode($metaPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        self::$templateHash = $hash;
        self::$currentHash = $hash;
        self::$pendingTemplateBuild = false;

        self::releaseLock();
    }

    /**
     * 初始化流程失败时清理状态，避免锁资源泄漏
     */
    public static function markDatabaseFailed(): void
    {
        if (self::isCacheDisabled()) {
            return;
        }

        if (null !== self::$currentDatabaseFile && !self::$pendingTemplateBuild) {
            // 已经是模板复用场景，只需要释放锁即可
            self::releaseLock();

            return;
        }

        self::$pendingTemplateBuild = false;
        self::releaseLock();
    }

    /**
     * 根据元数据与 fixtures 生成稳定指纹，用于判断模板是否可复用
     *
     * @param array<int, object> $metadata
     * @param array<int, object> $fixtures
     */
    public static function computeBootstrapHash(array $metadata, array $fixtures): string
    {
        $parts = [
            self::$cacheContext['project_dir'] ?? 'default_project',
            self::$cacheContext['kernel_class'] ?? 'default_kernel',
            self::$cacheContext['environment'],
        ];

        foreach ($metadata as $meta) {
            if (!is_object($meta) || !method_exists($meta, 'getName')) {
                continue;
            }

            $className = $meta->getName();
            $parts[] = $className;
            $parts[] = self::extractFileFingerprint($className);
        }

        foreach ($fixtures as $fixture) {
            $className = $fixture::class;
            $parts[] = $className;
            $parts[] = self::extractFileFingerprint($className);
        }

        $projectDir = self::$cacheContext['project_dir'];
        if (null !== $projectDir) {
            $composerLock = $projectDir . '/composer.lock';
            if (is_file($composerLock)) {
                $parts[] = filemtime($composerLock) !== false ? filemtime($composerLock) : 0;
                $parts[] = filesize($composerLock) !== false ? filesize($composerLock) : 0;
            }
        }

        return hash('sha256', implode('|', array_map(static fn ($part) => (string) $part, $parts)));
    }

    public static function cleanupGeneratedDatabases(): void
    {
        if (0 === count(self::$generatedDatabases)) {
            return;
        }

        foreach (self::$generatedDatabases as $databaseFile) {
            self::removeIfExists($databaseFile);
            foreach (['-wal', '-shm', '-journal'] as $suffix) {
                self::removeIfExists($databaseFile . $suffix);
            }
        }

        self::$generatedDatabases = [];
    }

    private static function initializeCacheMetadata(): void
    {
        self::$cacheKey = self::$cacheKey ?? self::computeBaseKey();
        $cacheDir = self::getCacheDirectory();

        self::$templatePath = $cacheDir . '/' . self::$cacheKey . '.sqlite';
        self::$metadataPath = $cacheDir . '/' . self::$cacheKey . '.meta.json';
        self::$lockFile = $cacheDir . '/' . self::$cacheKey . '.lock';

        self::ensureLockAcquired();

        if (!is_file(self::$templatePath ?? '') || !is_file(self::$metadataPath ?? '')) {
            self::$templateHash = null;

            return;
        }

        if (self::$metadataPath !== null) {
            $metaContent = file_get_contents(self::$metadataPath);
        } else {
            $metaContent = false;
        }
        if (false === $metaContent) {
            self::$templateHash = null;

            return;
        }

        try {
            $meta = json_decode($metaContent, true, 512, JSON_THROW_ON_ERROR);
            self::$templateHash = is_array($meta) && isset($meta['hash']) ? (string) $meta['hash'] : null;
        } catch (\JsonException) {
            self::$templateHash = null;
        }
    }

    private static function computeBaseKey(): string
    {
        $parts = [
            self::$cacheContext['project_dir'] ?? 'default_project',
            self::$cacheContext['kernel_class'] ?? 'default_kernel',
            self::$cacheContext['environment'],
        ];

        try {
            $parts[] = json_encode(self::$cacheContext['extra'], JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $parts[] = '';
        }

        return hash('sha1', implode('|', array_map(static fn ($part) => (string) $part, $parts)));
    }

    private static function createDatabaseFilePath(): string
    {
        $randomName = implode('_', [
            'test',
            getmypid(),
            md5(random_bytes(32)),
        ]);

        $dbFile = tempnam(sys_get_temp_dir(), $randomName . '_');
        if (false === $dbFile) {
            throw new \RuntimeException('Failed to create temporary database file for testing');
        }

        if (false !== getenv('TEST_TOKEN')) {
            // ParaTest 支持
            $dbFile .= '_' . getenv('TEST_TOKEN');
        }

        return $dbFile;
    }

    private static function extractFileFingerprint(string $className): string
    {
        try {
            if (!class_exists($className)) {
                return '';
            }
            $reflection = new \ReflectionClass($className);
            $fileName = $reflection->getFileName();
            if (false === $fileName || !is_file($fileName)) {
                return '';
            }

            return implode(':', [
                $fileName,
                filemtime($fileName) !== false ? filemtime($fileName) : 0,
                filesize($fileName) !== false ? filesize($fileName) : 0,
            ]);
        } catch (\ReflectionException) {
            return $className;
        }
    }

    private static function copyDatabaseFiles(string $source, string $target): void
    {
        if (!is_file($source)) {
            return;
        }

        if (!@copy($source, $target)) {
            throw new \RuntimeException(sprintf('复制数据库文件失败：%s -> %s', $source, $target));
        }

        foreach (['-wal', '-shm', '-journal'] as $suffix) {
            $sourceFile = $source . $suffix;
            $targetFile = $target . $suffix;

            if (is_file($sourceFile)) {
                @copy($sourceFile, $targetFile);
            }
        }
    }

    private static function ensureLockAcquired(): void
    {
        if (self::$lockOwned) {
            return;
        }

        if (null === self::$lockFile) {
            return;
        }

        $handle = fopen(self::$lockFile, 'c');
        if (false === $handle) {
            throw new \RuntimeException(sprintf('无法创建或打开锁文件：%s', self::$lockFile));
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);

            throw new \RuntimeException(sprintf('无法获取数据库缓存锁：%s', self::$lockFile));
        }

        self::$lockHandle = $handle;
        self::$lockOwned = true;
    }

    private static function releaseLock(): void
    {
        if (!self::$lockOwned) {
            return;
        }

        if (null !== self::$lockHandle) {
            flock(self::$lockHandle, LOCK_UN);
            fclose(self::$lockHandle);
        }

        self::$lockHandle = null;
        self::$lockOwned = false;
    }

    private static function removeIfExists(string $path): void
    {
        if ('' === $path) {
            return;
        }

        if (is_file($path)) {
            @unlink($path);
        }
    }

    private static function registerShutdownCleanup(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }

        register_shutdown_function(static function (): void {
            self::cleanupGeneratedDatabases();
        });

        self::$shutdownRegistered = true;
    }

    private static function getCacheDirectory(): string
    {
        $cacheDir = sys_get_temp_dir() . '/phpunit-db-cache';
        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0777, true) && !is_dir($cacheDir)) {
            throw new \RuntimeException(sprintf('无法创建数据库缓存目录：%s', $cacheDir));
        }

        return $cacheDir;
    }

    private static function buildSqliteUrl(string $path): string
    {
        return "sqlite:///file:{$path}";
    }

    private static function isCacheDisabled(): bool
    {
        if (self::$cacheDisabled) {
            return true;
        }

        $envValue = getenv('PHPUNIT_DISABLE_DATABASE_CACHE');
        if (false === $envValue) {
            return false;
        }

        return in_array(strtolower((string) $envValue), ['1', 'true', 'yes', 'on'], true);
    }
}
