<?php

namespace Tourze\PHPUnitSymfonyKernelTest;

use Tourze\PHPUnitSymfonyKernelTest\Exception\ClassNotFoundException;

/**
 * Bundle 推断器
 *
 * 根据测试类智能推断对应的 Bundle 类：
 * - 基于文件系统结构
 * - 基于命名空间
 * - 多种策略组合
 *
 * @author Claude
 */
class BundleInferrer
{
    /**
     * 根据测试类推断 Bundle 类
     *
     * @param string|\ReflectionClass<object> $testClass
     */
    public static function inferBundleClass($testClass): ?string
    {
        $reflection = self::getReflection($testClass);
        $testClassName = $reflection->getName();
        $testFile = $reflection->getFileName();

        if (false === $testFile) {
            return null;
        }

        // 策略1：基于文件系统查找
        $bundleClass = self::inferFromFilesystem($testFile, $testClassName);
        if (null !== $bundleClass) {
            return $bundleClass;
        }

        // 策略2：基于命名空间推断（向后兼容）
        return self::inferFromNamespace($testClassName);
    }

    /**
     * 从文件系统结构推断 Bundle 类
     */
    private static function inferFromFilesystem(string $testFile, string $testClass): ?string
    {
        $currentDir = dirname($testFile);
        $lastBackslashPos = strrpos($testClass, '\\');
        $testNamespace = false !== $lastBackslashPos ? substr($testClass, 0, $lastBackslashPos) : $testClass;

        // 向上递归查找包含 src 目录的位置
        while (self::isValidDirectory($currentDir)) {
            $bundleClass = self::findBundleInDirectory($currentDir, $testNamespace);
            if (null !== $bundleClass) {
                return $bundleClass;
            }

            $currentDir = dirname($currentDir);
        }

        return null;
    }

    /**
     * 在目录中查找 Bundle 文件
     */
    private static function findBundleInDirectory(string $directory, string $testNamespace): ?string
    {
        $searchPaths = [
            $directory . '/src',
            $directory,
            $directory . '/Bundle',
        ];

        foreach ($searchPaths as $searchPath) {
            $bundleClass = self::findBundleInPath($searchPath, $testNamespace);
            if (null !== $bundleClass) {
                return $bundleClass;
            }
        }

        return null;
    }

    /**
     * 在指定路径中查找 Bundle 文件
     */
    private static function findBundleInPath(string $path, string $testNamespace): ?string
    {
        if (!is_dir($path)) {
            return null;
        }

        $bundleFiles = glob($path . '/*Bundle.php');
        if ($bundleFiles === false || count($bundleFiles) === 0) {
            return null;
        }

        return self::selectBestMatchingBundle($bundleFiles, $testNamespace);
    }

    /**
     * 基于命名空间推断 Bundle 类（向后兼容）
     */
    private static function inferFromNamespace(string $testClass): ?string
    {
        $namespaceParts = explode('\\', $testClass);
        if (count($namespaceParts) < 2) {
            return null;
        }

        // 移除最后的类名部分
        array_pop($namespaceParts);

        $bundleNamespaceParts = self::extractBundleNamespace($namespaceParts);
        if (count($bundleNamespaceParts) === 0) {
            return null;
        }

        // 尝试多种 Bundle 命名模式
        $patterns = self::generateBundlePatterns($bundleNamespaceParts);

        return self::findExistingBundleClass($patterns);
    }

    /**
     * 提取 Bundle 命名空间部分
     *
     * @param array<string> $namespaceParts
     *
     * @return array<string>
     */
    private static function extractBundleNamespace(array $namespaceParts): array
    {
        $testsIndex = array_search('Tests', $namespaceParts, true);

        if (false !== $testsIndex && is_int($testsIndex)) {
            // 如果找到 Tests，取 Tests 之前的部分作为 Bundle 命名空间
            return array_slice($namespaceParts, 0, $testsIndex);
        }

        // 如果没有找到 Tests，假设整个命名空间都是 Bundle 命名空间
        return $namespaceParts;
    }

    /**
     * 查找存在的 Bundle 类
     *
     * @param array<string> $patterns
     */
    private static function findExistingBundleClass(array $patterns): ?string
    {
        foreach ($patterns as $bundleClass) {
            if (class_exists($bundleClass)) {
                return $bundleClass;
            }
        }

        return null;
    }

    /**
     * 生成可能的 Bundle 类名模式
     *
     * @param array<string> $namespaceParts
     *
     * @return array<string>
     */
    private static function generateBundlePatterns(array $namespaceParts): array
    {
        $patterns = [];
        $namespace = implode('\\', $namespaceParts);
        $lastPart = end($namespaceParts);

        // 基本模式
        $patterns[] = $namespace . '\\' . $lastPart . 'Bundle';
        $patterns[] = $namespace . '\Bundle\\' . $lastPart . 'Bundle';

        // 如果最后部分已经包含 Bundle
        if (false !== $lastPart && str_ends_with($lastPart, 'Bundle')) {
            $patterns[] = $namespace . '\\' . $lastPart;
        }

        // Bundle 目录模式
        $bundleIndex = array_search('Bundle', $namespaceParts, true);
        if (false !== $bundleIndex && is_int($bundleIndex) && $bundleIndex < count($namespaceParts) - 1) {
            $bundleNamespace = implode('\\', array_slice($namespaceParts, 0, $bundleIndex + 2));
            $patterns[] = $bundleNamespace;
        }

        return array_unique($patterns);
    }

    /**
     * 从多个 Bundle 文件中选择最匹配的
     *
     * @param array<string> $bundleFiles
     */
    private static function selectBestMatchingBundle(array $bundleFiles, string $testNamespace): ?string
    {
        $candidates = [];

        foreach ($bundleFiles as $bundleFile) {
            $candidate = self::extractBundleCandidate($bundleFile, $testNamespace);
            if (null !== $candidate) {
                $candidates[] = $candidate;
            }
        }

        if (count($candidates) === 0) {
            return null;
        }

        // 按分数排序，选择最高分的
        usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $candidates[0]['class'];
    }

    /**
     * 提取单个 Bundle 候选项
     *
     * @return array{class: string, score: int}|null
     */
    private static function extractBundleCandidate(string $bundleFile, string $testNamespace): ?array
    {
        $bundleClassName = basename($bundleFile, '.php');

        // 尝试从文件内容中提取完整的类名
        $content = file_get_contents($bundleFile);
        if (false === $content) {
            return null;
        }

        $matchResult = preg_match('/namespace\s+([^;]+);/', $content, $matches);
        if (1 !== $matchResult) {
            return null;
        }

        $bundleNamespace = $matches[1];
        $fullClassName = $bundleNamespace . '\\' . $bundleClassName;

        if (!class_exists($fullClassName)) {
            return null;
        }

        return [
            'class' => $fullClassName,
            'score' => self::calculateNamespaceMatchScore($testNamespace, $bundleNamespace),
        ];
    }

    /**
     * 计算命名空间匹配度分数
     */
    private static function calculateNamespaceMatchScore(string $testNamespace, string $bundleNamespace): int
    {
        $testParts = explode('\\', $testNamespace);
        $bundleParts = explode('\\', $bundleNamespace);

        $score = 0;
        $minLength = min(count($testParts), count($bundleParts));

        // 从前往后匹配，越前面的权重越高
        for ($i = 0; $i < $minLength; ++$i) {
            if ($testParts[$i] === $bundleParts[$i]) {
                $score += ($minLength - $i) * 10;
            }
        }

        // 各种加分规则
        $score += self::calculateBonusScore($testNamespace, $bundleNamespace, $testParts, $bundleParts);

        return $score;
    }

    /**
     * 计算加分规则
     *
     * @param array<string> $testParts
     * @param array<string> $bundleParts
     */
    private static function calculateBonusScore(string $testNamespace, string $bundleNamespace, array $testParts, array $bundleParts): int
    {
        $score = 0;

        // 如果 Bundle 命名空间是测试命名空间的前缀，额外加分
        if (str_starts_with($testNamespace, $bundleNamespace)) {
            $score += 100;
        }

        // 如果共享相同的根命名空间，加分
        if (count($testParts) > 0 && count($bundleParts) > 0 && $testParts[0] === $bundleParts[0]) {
            $score += 50;
        }

        return $score;
    }

    /**
     * 获取 Bundle 的候选列表（用于调试）
     *
     * @param string|\ReflectionClass<object> $testClass
     *
     * @return array<string>
     */
    public static function getCandidates($testClass): array
    {
        $reflection = self::getReflection($testClass);
        $testClassName = $reflection->getName();
        $candidates = [];

        // 从命名空间生成候选
        $namespaceCandidates = self::getNamespaceCandidates($testClassName);
        $candidates = array_merge($candidates, $namespaceCandidates);

        // 从文件系统查找
        $filesystemCandidates = self::getFilesystemCandidates($reflection);
        $candidates = array_merge($candidates, $filesystemCandidates);

        return array_unique($candidates);
    }

    /**
     * 获取命名空间候选项
     *
     * @return array<string>
     */
    private static function getNamespaceCandidates(string $testClassName): array
    {
        $namespaceParts = explode('\\', $testClassName);
        array_pop($namespaceParts);

        if (count($namespaceParts) === 0) {
            return [];
        }

        $bundleNamespaceParts = self::extractBundleNamespace($namespaceParts);
        if (count($bundleNamespaceParts) === 0) {
            return [];
        }

        return self::generateBundlePatterns($bundleNamespaceParts);
    }

    /**
     * 获取文件系统候选项
     *
     * @param \ReflectionClass<object> $reflection
     *
     * @return array<string>
     */
    private static function getFilesystemCandidates(\ReflectionClass $reflection): array
    {
        $candidates = [];
        $testFile = $reflection->getFileName();

        if (false === $testFile) {
            return $candidates;
        }

        $currentDir = dirname($testFile);
        while (self::isValidDirectory($currentDir)) {
            $dirCandidates = self::getDirectoryCandidates($currentDir);
            $candidates = array_merge($candidates, $dirCandidates);
            $currentDir = dirname($currentDir);
        }

        return $candidates;
    }

    /**
     * 获取目录候选项
     *
     * @return array<string>
     */
    private static function getDirectoryCandidates(string $directory): array
    {
        $candidates = [];
        $patterns = [
            $directory . '/src/*Bundle.php',
            $directory . '/*Bundle.php',
            $directory . '/Bundle/*Bundle.php',
        ];

        foreach ($patterns as $pattern) {
            $files = glob($pattern);
            if (false === $files) {
                continue;
            }
            foreach ($files as $file) {
                $content = file_get_contents($file);
                if (false === $content) {
                    continue;
                }
                $matchResult = preg_match('/namespace\s+([^;]+);/', $content, $matches);
                if (1 === $matchResult) {
                    $candidates[] = $matches[1] . '\\' . basename($file, '.php');
                }
            }
        }

        return $candidates;
    }

    /**
     * 获取反射对象
     *
     * @param string|\ReflectionClass<object> $testClass
     *
     * @return \ReflectionClass<object>
     */
    private static function getReflection($testClass): \ReflectionClass
    {
        if (is_string($testClass)) {
            if (!class_exists($testClass)) {
                throw new ClassNotFoundException("Class '{$testClass}' does not exist");
            }

            return new \ReflectionClass($testClass);
        }

        return $testClass;
    }

    /**
     * 检查目录是否有效
     */
    private static function isValidDirectory(string $directory): bool
    {
        return '/' !== $directory && '.' !== $directory;
    }
}
