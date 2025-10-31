<?php

namespace Tourze\PHPUnitSymfonyKernelTest\Exception;

class KernelCreationException extends \Exception
{
    /**
     * @param array<string, mixed> $debugInfo
     */
    public function __construct(
        private readonly string $testClass,
        string $message = '',
        private readonly array $debugInfo = [],
    ) {
        $fullMessage = $this->buildErrorMessage($message);
        parent::__construct($fullMessage);
    }

    private function buildErrorMessage(string $originalMessage): string
    {
        $message = "Failed to create kernel for test class: {$this->testClass}\n";

        if ('' !== $originalMessage) {
            $message .= "Original error: {$originalMessage}\n";
        }

        $message .= "\nPossible solutions:\n";
        $message .= "1. Add KERNEL_CLASS=Your\\Test\\Kernel to your project's .env.test file\n";
        $message .= "2. Implement getProjectKernelClass() method to return your project's TestKernel class\n";
        $message .= "3. Implement configureBundles() method to specify required bundles\n";
        $message .= "4. Ensure your project has a Bundle class for auto-inference\n\n";

        if (isset($this->debugInfo['bundle_candidates']) && is_array($this->debugInfo['bundle_candidates']) && count($this->debugInfo['bundle_candidates']) > 0) {
            $message .= 'Bundle candidates found: ' . implode(', ', $this->debugInfo['bundle_candidates']) . "\n";
        }

        if (isset($this->debugInfo['suggestions']) && count($this->debugInfo['suggestions']) > 0) {
            $message .= "Suggestions:\n";
            foreach ($this->debugInfo['suggestions'] as $suggestion) {
                $message .= "- {$suggestion}\n";
            }
        }

        return $message;
    }

    public function getTestClass(): string
    {
        return $this->testClass;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDebugInfo(): array
    {
        return $this->debugInfo;
    }
}
