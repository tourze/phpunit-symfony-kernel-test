<?php

namespace Tourze\PHPUnitSymfonyKernelTest\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use Tourze\PHPUnitSymfonyKernelTest\AbstractIntegrationTestCase;
use Tourze\PHPUnitSymfonyKernelTest\DatabaseHelper;

/**
 * 数据库辅助工具测试
 * @internal
 */
#[CoversClass(DatabaseHelper::class)]
#[RunTestsInSeparateProcesses]
class DatabaseHelperTest extends AbstractIntegrationTestCase
{
    protected function onSetUp(): void
    {
        // 无特殊初始化需求
    }

    #[Test]
    public function 可以生成唯一的数据库URL(): void
    {
        $databaseUrl = DatabaseHelper::generateUniqueDatabaseUrl();

        $this->assertIsString($databaseUrl);
        $this->assertStringStartsWith('sqlite:///', $databaseUrl);
    }

    #[Test]
    public function 每次生成的数据库URL都是唯一的(): void
    {
        $url1 = DatabaseHelper::generateUniqueDatabaseUrl();
        $url2 = DatabaseHelper::generateUniqueDatabaseUrl();

        $this->assertNotEquals($url1, $url2);
        $this->assertIsString($url1);
        $this->assertIsString($url2);
    }

    #[Test]
    public function 生成的数据库URL包含进程ID(): void
    {
        $databaseUrl = DatabaseHelper::generateUniqueDatabaseUrl();

        // URL应该包含进程ID作为标识符
        $this->assertStringContainsString((string) getmypid(), $databaseUrl);
    }

    #[Test]
    public function 生成的URL是有效的SQLite格式(): void
    {
        $databaseUrl = DatabaseHelper::generateUniqueDatabaseUrl();

        // 验证URL格式
        $this->assertStringStartsWith('sqlite:///file:', $databaseUrl);
        $this->assertStringContainsString('test', $databaseUrl);
    }

    #[Test]
    public function 在并发环境下生成唯一URL(): void
    {
        $urls = [];

        // 生成多个URL验证唯一性
        for ($i = 0; $i < 10; ++$i) {
            $urls[] = DatabaseHelper::generateUniqueDatabaseUrl();
        }

        $uniqueUrls = array_unique($urls);
        $this->assertCount(10, $uniqueUrls, '所有生成的URL应该都是唯一的');
    }

    #[Test]
    public function uRL包含随机哈希组件(): void
    {
        $databaseUrl = DatabaseHelper::generateUniqueDatabaseUrl();

        // URL应该包含32字符的MD5哈希
        $this->assertMatchesRegularExpression('/[a-f0-9]{32}/', $databaseUrl);
    }

    #[Test]
    public function 支持ParaTest环境变量(): void
    {
        // 模拟ParaTest环境
        $oldTestToken = getenv('TEST_TOKEN');
        putenv('TEST_TOKEN=test_token_123');

        $databaseUrl = DatabaseHelper::generateUniqueDatabaseUrl();

        $this->assertStringContainsString('test_token_123', $databaseUrl);

        // 恢复原始环境变量
        if (false !== $oldTestToken) {
            putenv("TEST_TOKEN={$oldTestToken}");
        } else {
            putenv('TEST_TOKEN');
        }
    }

    #[Test]
    public function 没有TESTTOKEN时正常工作(): void
    {
        // 确保没有TEST_TOKEN环境变量
        $oldTestToken = getenv('TEST_TOKEN');
        putenv('TEST_TOKEN');

        $databaseUrl = DatabaseHelper::generateUniqueDatabaseUrl();

        $this->assertIsString($databaseUrl);
        $this->assertStringStartsWith('sqlite:///file:', $databaseUrl);

        // 恢复原始环境变量
        if (false !== $oldTestToken) {
            putenv("TEST_TOKEN={$oldTestToken}");
        }
    }

    #[Test]
    public function uRL路径指向临时目录(): void
    {
        $databaseUrl = DatabaseHelper::generateUniqueDatabaseUrl();

        // 提取文件路径部分
        $filePath = str_replace('sqlite:///file:', '', $databaseUrl);
        $dirname = dirname($filePath);

        // 应该使用系统临时目录，使用realpath处理软链接
        $expectedTempDir = realpath(sys_get_temp_dir());
        $actualTempDir = realpath($dirname);

        $this->assertEquals($expectedTempDir, $actualTempDir);
    }

    #[Test]
    public function 生成的文件名包含test前缀(): void
    {
        $databaseUrl = DatabaseHelper::generateUniqueDatabaseUrl();

        $this->assertStringContainsString('test', $databaseUrl);
    }
}
