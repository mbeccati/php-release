<?php

declare(strict_types=1);

namespace PhpRelease\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class BuildCloneTest extends TestCase
{
    private string $directory;
    private string $source;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/php-release-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->source = $this->directory . '/php-src';
        $this->runCommand(['git', 'init', '--initial-branch=master', $this->source], $this->directory);
        $this->git(['config', 'user.name', 'Clone Test'], $this->source);
        $this->git(['config', 'user.email', 'test@example.invalid'], $this->source);
        // More than the default depth, so a full clone cannot pass the depth assertion.
        for ($i = 0; $i < 105; ++$i) {
            $this->git(['commit', '--allow-empty', '-m', "Commit $i"], $this->source);
        }
        $this->git(['branch', 'PHP-8.5'], $this->source);
        $this->git(['branch', 'PHP-8.6'], $this->source);
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->directory);
    }

    public function testUnsetAdditionalBranchesClonesOnlyReleaseAtDefaultDepth(): void
    {
        $clone = $this->cloneRelease();
        $this->assertOnlyReleaseBranch($clone);
        $this->assertDepth($clone, 'PHP-8.5', 100);
    }

    public function testEmptyAdditionalBranchesClonesOnlyRelease(): void
    {
        $clone = $this->cloneRelease(['ADDITIONAL_BRANCHES' => '', 'CLONE_DEPTH' => '2']);
        $this->assertOnlyReleaseBranch($clone);
        $this->assertDepth($clone, 'PHP-8.5', 2);
    }

    public function testAdditionalBranchesAreShallowAndCanBeCheckedOut(): void
    {
        $clone = $this->cloneRelease(['ADDITIONAL_BRANCHES' => 'PHP-8.6 master']);
        foreach (['PHP-8.6', 'master'] as $branch) {
            $this->assertDepth($clone, $branch, 100);
            $this->git(['checkout', $branch], $clone);
            self::assertSame($branch, $this->git(['branch', '--show-current'], $clone));
            self::assertSame('upstream/' . $branch, $this->git(['rev-parse', '--abbrev-ref', '@{upstream}'], $clone));
            self::assertContains('+refs/heads/' . $branch . ':refs/remotes/upstream/' . $branch,
                explode("\n", $this->git(['config', '--get-all', 'remote.upstream.fetch'], $clone)));
        }
    }

    public function testExplicitDepthAppliesToAdditionalBranchesAndExistingReleaseBranch(): void
    {
        $clone = $this->cloneRelease([
            'ADDITIONAL_BRANCHES' => 'master PHP-8.5',
            'CLONE_DEPTH' => '3',
        ]);
        $this->assertDepth($clone, 'PHP-8.5', 3);
        $this->assertDepth($clone, 'master', 3);
    }

    public function testMissingAdditionalBranchFailsTheBuild(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("couldn't find remote ref refs/heads/missing");
        $this->cloneRelease(['ADDITIONAL_BRANCHES' => 'missing']);
    }

    private function cloneRelease(array $settings = []): string
    {
        $work = $this->directory . '/work';
        mkdir($work);
        $script = file_get_contents(dirname(__DIR__) . '/build.sh');
        // Execute the actual clone/fetch block without running packaging or GPG setup.
        $start = strpos($script, 'git clone -b');
        self::assertNotFalse($start);
        $end = strpos($script, "\n" . 'if [ ! -z "${CUT_RELEASE_BRANCH}" ]; then', $start);
        self::assertNotFalse($end);
        $block = substr($script, $start, $end - $start);
        $block = str_replace('cd /workspace/php-src', 'cd php-src', $block);
        $this->runCommand(['bash', '-e', '-c', $block], $work, array_merge([
            'PHP_REPO_FETCH' => 'file://' . $this->source,
            'RELEASE_BRANCH' => 'PHP-8.5',
        ], $settings));
        $clone = $work . '/php-src';
        self::assertSame('PHP-8.5', $this->git(['branch', '--show-current'], $clone));
        return $clone;
    }

    private function assertOnlyReleaseBranch(string $clone): void
    {
        self::assertSame('PHP-8.5', $this->git(['branch', '--format=%(refname:short)'], $clone));
        self::assertSame('upstream/PHP-8.5', $this->git(['branch', '-r', '--format=%(refname:short)'], $clone));
    }

    private function assertDepth(string $clone, string $branch, int $depth): void
    {
        self::assertSame('true', $this->git(['rev-parse', '--is-shallow-repository'], $clone));
        self::assertSame((string) $depth, $this->git(['rev-list', '--count', $branch], $clone));
    }

    private function git(array $arguments, string $cwd): string
    {
        return $this->runCommand(array_merge(['git'], $arguments), $cwd);
    }

    private function runCommand(array $command, string $cwd, array $settings = []): string
    {
        $environment = array_merge([
            'PATH' => getenv('PATH'),
            'HOME' => $this->directory,
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_TERMINAL_PROMPT' => '0',
        ], $settings);
        $output = tmpfile();
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output], $pipes, $cwd, $environment);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start command');
        }
        $status = proc_close($process);
        rewind($output);
        $text = stream_get_contents($output);
        fclose($output);
        if ($status !== 0) {
            throw new RuntimeException(implode(' ', $command) . " failed:\n" . $text);
        }
        return trim($text);
    }
}
