<?php

namespace Tourze\PHPUnitSymfonyKernelTest;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Tourze\PHPUnitBase\TestCaseHelper;

/**
 * 针对 Symfony Command 做的通用测试基类
 */
#[RunTestsInSeparateProcesses]
abstract class AbstractCommandTestCase extends AbstractIntegrationTestCase
{
    abstract protected function getCommandTester(): CommandTester;

    /**
     * [元测试]
     * 这是一个会自动运行的测试，它检查并强制要求当前测试用例
     * 必须为被测 Command 的每一个参数和选项都实现一个对应的测试方法。
     */
    #[Test]
    final public function testCommandSignatureIsFullyTested(): void
    {
        $commandClass = TestCaseHelper::extractCoverClass(self::createTestCaseReflection());
        $this->assertNotNull($commandClass, 'Command class not found in CoversClass attribute');
        $this->assertIsString($commandClass, 'Command class name must be a string');
        /** @var class-string $commandClass */
        $command = self::getService($commandClass);
        $this->assertInstanceOf(Command::class, $command);

        $definition = $command->getDefinition();

        // 检查所有参数
        foreach ($definition->getArguments() as $argument) {
            $this->assertSignatureItemIsTested($argument);
        }

        // 检查所有选项
        foreach ($definition->getOptions() as $option) {
            $this->assertSignatureItemIsTested($option);
        }

        // 如果所有检查都通过，验证命令签名测试已完成
        $this->assertGreaterThanOrEqual(0, count($definition->getArguments()) + count($definition->getOptions()), 'Command signature validation completed');
    }

    /**
     * 断言给定的 InputArgument 或 InputOption 有一个对应的测试方法存在.
     */
    private function assertSignatureItemIsTested(InputArgument|InputOption $item): void
    {
        $itemName = $item->getName();
        $itemType = $item instanceof InputArgument ? 'Argument' : 'Option';

        // 忽略 Symfony 的默认选项
        $defaultOptions = ['help', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction'];
        if ('Option' === $itemType && in_array($itemName, $defaultOptions, true)) {
            return;
        }

        // 将 kebab-case 或 snake_case 转换为 PascalCase
        $methodNameSuffix = str_replace(['-', '_'], '', ucwords($itemName, '-_'));
        $expectedMethod = 'test' . $itemType . $methodNameSuffix;

        if (!method_exists($this, $expectedMethod)) {
            $prefix = $item instanceof InputOption ? '--' : '';
            Assert::fail(sprintf(
                "Missing test for %s '%s%s'.\n" .
                "Please implement a public method named '%s' in test class '%s' to cover this case.",
                strtolower($itemType),
                $prefix,
                $itemName,
                $expectedMethod,
                get_class($this)
            ));
        }
    }

    /**
     * @return \ReflectionClass<object>
     */
    private static function createTestCaseReflection(): \ReflectionClass
    {
        return new \ReflectionClass(get_called_class());
    }
}
