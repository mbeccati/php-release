<?php

declare(strict_types=1);

namespace PhpRelease\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

abstract class ScriptTestCase extends TestCase
{
    protected string $sandbox;
    protected string $workspace;
    protected string $source;
    protected string $git;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/php-release-tests-' . bin2hex(random_bytes(8));
        $this->workspace = $this->sandbox . '/workspace';
        $this->source = $this->workspace . '/php-src';
        mkdir($this->sandbox, 0700);
        mkdir($this->workspace);
        mkdir($this->sandbox . '/bin');
        mkdir($this->sandbox . '/home');
        $this->git = trim($this->execute(['bash', '-c', 'command -v git'])['output']);
        self::assertNotSame('', $this->git, 'Git must be installed');

        // Only relocate container paths in temporary copies. The repository scripts
        // remain unchanged; no command can touch the real /workspace or GPG home.
        foreach (['build.sh', 'parse-version.sh', 'update-versions.sh'] as $script) {
            $contents = file_get_contents(dirname(__DIR__) . '/' . $script);
            $contents = strtr($contents, [
                '/workspace' => $this->workspace,
                '/parse-version.sh' => $this->sandbox . '/parse-version.sh',
                '/update-versions.sh' => $this->sandbox . '/update-versions.sh',
                '/tmp/gnupg' => $this->sandbox . '/gnupg',
                '/root/.gnupg' => $this->sandbox . '/home/.gnupg',
            ]);
            $this->write($this->sandbox . '/' . $script, $contents);
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->sandbox) || !is_dir($this->sandbox)) {
            return;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->sandbox, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->sandbox);
    }

    protected function write(string $path, string $contents, bool $executable = false): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $contents);
        if ($executable) {
            chmod($path, 0700);
        }
    }

    /** @return array{status: int, output: string} */
    protected function execute(array $command, array $environment = [], ?string $cwd = null): array
    {
        // Use a fresh environment so developer Git settings and release variables
        // cannot alter the tests. Merge stderr into stdout to avoid pipe deadlocks.
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes,
            $cwd ?? $this->sandbox, array_merge([
                'PATH' => $this->sandbox . '/bin:/usr/bin:/bin',
                'HOME' => $this->sandbox . '/home',
                'LC_ALL' => 'C',
                'TZ' => 'UTC',
                'GIT_CONFIG_NOSYSTEM' => '1',
                'GIT_CONFIG_GLOBAL' => '/dev/null',
                'GIT_TERMINAL_PROMPT' => '0',
                'TEST_SANDBOX' => $this->sandbox,
            ], $environment));
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return ['status' => proc_close($process), 'output' => $output];
    }

    protected function succeed(array $command, array $environment = [], ?string $cwd = null): string
    {
        $result = $this->execute($command, $environment, $cwd);
        self::assertSame(0, $result['status'], $result['output']);
        return $result['output'];
    }

    protected function fixture(string $directory, string $news = "PHP NEWS\n========\n?? ??? ????, PHP 8.6.0\n\nChanges\n"): void
    {
        $this->write($directory . '/NEWS', $news);
        $this->write($directory . '/main/php_version.h', "old version\n");
        $this->write($directory . '/main/php.h', "#define PHP_API_VERSION 20250925\n");
        $this->write($directory . '/Zend/zend_extensions.h', "#define ZEND_EXTENSION_API_NO 420250925\n");
        $this->write($directory . '/Zend/zend_modules.h', "#define ZEND_MODULE_API_NO 20250925\n");
        $this->write($directory . '/Zend/zend.h', "#define ZEND_VERSION \"4.5.0\"\n");
        $this->write($directory . '/configure.ac', "AC_INIT([PHP], [8.6.0-dev], [https://github.com/php/php-src/issues])\n");
        $this->succeed([$this->git, 'init', '-b', 'PHP-8.6', $directory]);
        $this->succeed([$this->git, 'config', 'user.name', 'Test Builder'], [], $directory);
        $this->succeed([$this->git, 'config', 'user.email', 'builder@example.com'], [], $directory);
        $this->succeed([$this->git, 'add', '.'], [], $directory);
        $this->succeed([$this->git, 'commit', '-m', 'Initial fixture'], [], $directory);
    }
}
