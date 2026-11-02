<?php

declare(strict_types=1);

namespace PhpRelease\Tests;

use PHPUnit\Framework\Attributes\DataProvider;

final class BuildTest extends ScriptTestCase
{
    private function prepare(array $config = [], string $news = "PHP NEWS\n========\n?? ??? ????, PHP 8.6.0\n\nChanges\n"): void
    {
        $upstream = $this->sandbox . '/upstream/php-src';
        $this->fixture($upstream, $news);
        $this->write($upstream . '/scripts/dev/credits', <<<'BASH'
#!/bin/bash
if [ "${TEST_CREDITS_CHANGE:-0}" = 1 ]; then
  echo 'updated credits' > ext/standard/credits_ext.h
fi
BASH, true);
        $this->write($upstream . '/ext/standard/credits_ext.h', "old credits\n");
        $this->write($upstream . '/ext/standard/credits_sapi.h', "old credits\n");
        $this->write($upstream . '/buildconf', "#!/bin/bash\nexit \"\${TEST_BUILDCONF_STATUS:-0}\"\n", true);
        $this->write($upstream . '/configure', <<<'BASH'
#!/bin/bash
printf '%s\n' "$*" >> "$TEST_SANDBOX/configure-calls"
echo 'configure output'
exit "${TEST_CONFIGURE_STATUS:-0}"
BASH, true);
        $this->write($upstream . '/sapi/cli/php', <<<'BASH'
#!/bin/bash
if [ "$1" = -n ]; then
  echo "PHP ${TEST_BUILT_VERSION:-$RELEASE_VERSION} (cli)"
else
  printf '%s | REPORT_EXIT_STATUS=%s\n' "$*" "$REPORT_EXIT_STATUS" >> "$TEST_SANDBOX/test-calls"
  if [ "$REPORT_EXIT_STATUS" = 1 ]; then
    exit "${TEST_PHP_STATUS:-0}"
  fi
fi
BASH, true);
        $this->write($upstream . '/scripts/dev/makedist', <<<'BASH'
#!/bin/bash
echo "$*" >> "$TEST_SANDBOX/package-calls"
if [ "${TEST_PACKAGE_STATUS:-0}" != 0 ]; then
  exit "$TEST_PACKAGE_STATUS"
fi
for extension in gz bz2 xz; do
  echo 'test archive' > "$1.tar.$extension"
  echo 'test signature' > "$1.tar.$extension.asc"
done
BASH, true);
        $this->write($upstream . '/scripts/dev/gen_verify_stub', "#!/bin/bash\nprintf 'Manifest for %s signed by %s\\n' \"\$1\" \"\$2\"\n", true);
        $this->succeed([$this->git, 'add', '.'], [], $upstream);
        $this->succeed([$this->git, 'commit', '-m', 'Add build fixtures'], [], $upstream);
        $this->write($this->sandbox . '/gnupg/test-key', "fake test key\n");
        // Real local Git provides commit, tag, reset, and staging semantics. Only
        // signing is disabled; release scripts still configure their signing key.
        $this->write($this->sandbox . '/bin/git', "#!/bin/bash\n"
            . 'if [ "$1" = push ]; then echo "Tests must never push" >&2; exit 99; fi' . "\n"
            . 'if [ "$1" = clone ] && [ "${!#}" != "$TEST_SANDBOX/upstream/php-src" ] && [ "${!#}" != "file://$TEST_SANDBOX/upstream/php-src" ]; then' . "\n"
            . '  echo "Tests may only clone their local fixture" >&2; exit 99' . "\nfi\n"
            . 'printf \'%s\n\' "$*" >> "$TEST_SANDBOX/git-calls"' . "\n"
            . 'exec ' . escapeshellarg($this->git) . ' -c commit.gpgsign=false -c tag.gpgSign=false "$@"' . "\n", true);
        $this->write($this->sandbox . '/bin/tty', "#!/bin/bash\necho /dev/test-tty\n", true);
        $this->write($this->sandbox . '/bin/sleep', "#!/bin/bash\necho \"\$*\" >> \"\$TEST_SANDBOX/sleep-calls\"\n", true);
        $this->write($this->sandbox . '/bin/make', <<<'BASH'
#!/bin/bash
echo "$*" >> "$TEST_SANDBOX/make-calls"
echo 'make output'
exit "${TEST_MAKE_STATUS:-0}"
BASH, true);
        $values = array_merge([
            'COMMITTER_NAME' => 'Test Builder',
            'COMMITTER_EMAIL' => 'builder@example.com',
            'RELEASE_DATE' => '2026-10-08',
            'RELEASE_BRANCH' => 'PHP-8.6',
            'RELEASE_VERSION' => '8.6.0',
            'GPG_KEY' => 'TEST-KEY',
            'PHP_REPO_FETCH' => $upstream,
            'PHP_REPO_PUSH' => $this->sandbox . '/push-target',
            'MAKE_TESTS' => '0',
            'MAKE_JOBS' => '3',
            'TEST_JOBS' => '2',
        ], $config);
        $contents = '';
        foreach ($values as $name => $value) {
            if ($value !== null) {
                // Export because fixture commands observe these values as well.
                $contents .= 'export ' . $name . '=' . escapeshellarg((string) $value) . "\n";
            }
        }
        $this->write($this->workspace . '/config', $contents);
    }

    private function build(array $environment = []): array
    {
        return $this->execute(['bash', $this->sandbox . '/build.sh'], $environment);
    }

    private function gitOutput(string ...$arguments): string
    {
        return $this->succeed(array_merge([$this->git], $arguments), [], $this->source);
    }

    private function assertNoPackages(): void
    {
        self::assertFileDoesNotExist($this->sandbox . '/package-calls');
        self::assertSame('', $this->gitOutput('tag', '--list'));
    }

    public function testGitWrapperRejectsPushAndExternalClone(): void
    {
        $this->prepare();
        $push = $this->execute([$this->sandbox . '/bin/git', 'push', 'origin', 'HEAD']);
        self::assertSame(99, $push['status']);
        self::assertStringContainsString('Tests must never push', $push['output']);
        $clone = $this->execute([$this->sandbox . '/bin/git', 'clone', 'https://example.invalid/php-src']);
        self::assertSame(99, $clone['status']);
        self::assertStringContainsString('Tests may only clone their local fixture', $clone['output']);
    }

    public function testMissingConfigurationFailsBeforeCloning(): void
    {
        $result = $this->build();
        self::assertSame(1, $result['status']);
        self::assertStringContainsString('Missing ' . $this->workspace . '/config', $result['output']);
        self::assertDirectoryDoesNotExist($this->source);
    }

    public static function requiredConfiguration(): array
    {
        return array_map(static fn (string $key): array => [$key],
            ['COMMITTER_NAME', 'COMMITTER_EMAIL', 'RELEASE_BRANCH', 'RELEASE_VERSION', 'GPG_KEY']);
    }

    #[DataProvider('requiredConfiguration')]
    public function testRequiredConfigurationFailsBeforeCloning(string $missing): void
    {
        $this->prepare([$missing => null]);
        $result = $this->build();
        self::assertNotSame(0, $result['status']);
        self::assertStringContainsString('is not set', $result['output']);
        self::assertDirectoryDoesNotExist($this->source);
    }

    public function testMissingGpgDirectoryFailsBeforeCloning(): void
    {
        $this->prepare();
        rename($this->sandbox . '/gnupg', $this->sandbox . '/unused-gnupg');
        $result = $this->build();
        self::assertSame(1, $result['status']);
        self::assertStringContainsString('Please bind mount', $result['output']);
        self::assertDirectoryDoesNotExist($this->source);
    }

    public function testPackagesTaggedReleaseAndRestoresBranchToNewsCommit(): void
    {
        $this->prepare();
        $result = $this->build();
        self::assertSame(0, $result['status'], $result['output']);
        self::assertSame("php-8.6.0\n", $this->gitOutput('tag', '--list'));
        self::assertSame("Keep the NEWS file\n", $this->gitOutput('config', '--local', '--get', 'merge.NEWS.name'));
        self::assertSame("touch %A\n", $this->gitOutput('config', '--local', '--get', 'merge.NEWS.driver'));
        self::assertStringContainsString('#define PHP_VERSION "8.6.0"', $this->gitOutput('show', 'php-8.6.0:main/php_version.h'));
        self::assertSame("old version\n", file_get_contents($this->source . '/main/php_version.h'));
        self::assertStringContainsString('08 Oct 2026, PHP 8.6.0', file_get_contents($this->source . '/NEWS'));
        self::assertSame("Update NEWS for PHP 8.6.0\n", $this->gitOutput('log', '-1', '--format=%s'));
        self::assertSame("Update versions for PHP 8.6.0\n", $this->gitOutput('log', '-1', '--format=%s', 'php-8.6.0'));
        foreach (['gz', 'bz2', 'xz'] as $extension) {
            self::assertFileExists($this->source . '/php-8.6.0.tar.' . $extension);
            self::assertFileExists($this->source . '/php-8.6.0.tar.' . $extension . '.asc');
        }
        self::assertSame("Manifest for 8.6.0 signed by TEST-KEY\n", file_get_contents($this->source . '/php-8.6.0.manifest'));
        self::assertStringContainsString("git push upstream 'php-8.6.0' 'PHP-8.6'", $result['output']);
        self::assertStringContainsString('php-distributions', $result['output']);
        self::assertFileDoesNotExist($this->sandbox . '/configure-calls');
        self::assertDoesNotMatchRegularExpression('/^push /m', file_get_contents($this->sandbox . '/git-calls'));
    }

    public static function matrices(): array
    {
        return [
            'disabled' => ['0', []],
            'debug ZTS only' => ['1', ['debug-zts']],
            'all' => ['2', ['nts', 'zts', 'debug-nts', 'debug-zts']],
            'default' => [null, ['nts', 'zts', 'debug-nts', 'debug-zts']],
        ];
    }

    #[DataProvider('matrices')]
    public function testBuildMatrixAndParallelism(?string $level, array $variants): void
    {
        $this->prepare(['MAKE_TESTS' => $level]);
        $result = $this->build();
        self::assertSame(0, $result['status'], $result['output']);
        if ($variants === []) {
            self::assertFileDoesNotExist($this->sandbox . '/configure-calls');
            self::assertFileDoesNotExist($this->sandbox . '/test-calls');
            return;
        }
        $configure = file($this->sandbox . '/configure-calls', FILE_IGNORE_NEW_LINES);
        $tests = file($this->sandbox . '/test-calls', FILE_IGNORE_NEW_LINES);
        self::assertCount(count($variants), $configure);
        self::assertCount(count($variants), $tests);
        self::assertSame(array_fill(0, count($variants), '-j3'), file($this->sandbox . '/make-calls', FILE_IGNORE_NEW_LINES));
        foreach ($variants as $index => $variant) {
            self::assertStringContainsString('--disable-all', $configure[$index]);
            self::assertStringContainsString('--enable-opcache-jit', $configure[$index]);
            self::assertSame(str_contains($variant, 'debug'), str_contains($configure[$index], '--enable-debug'));
            self::assertSame(str_ends_with($variant, 'zts') && !str_ends_with($variant, 'nts'), str_contains($configure[$index], '--enable-zts'));
            self::assertStringContainsString('-q -j2 -s ' . $this->workspace . '/log/tests.' . $variant . '.log', $tests[$index]);
            self::assertStringContainsString('--show-diff -g FAIL --offline | REPORT_EXIT_STATUS=1', $tests[$index]);
            self::assertSame("configure output\n", file_get_contents($this->workspace . '/log/config.' . $variant . '.log'));
            self::assertSame("make output\n", file_get_contents($this->workspace . '/log/make.' . $variant . '.log'));
        }
    }

    public function testVersionMismatchAbortsBeforeTagging(): void
    {
        $this->prepare(['MAKE_TESTS' => '1']);
        $result = $this->build(['TEST_BUILT_VERSION' => '8.5.0']);
        self::assertSame(1, $result['status']);
        self::assertStringContainsString('BUILT_VERSION=8.5.0', $result['output']);
        self::assertFileDoesNotExist($this->sandbox . '/test-calls');
        $this->assertNoPackages();
    }

    public function testPhpTestFailureAbortsBeforeTagging(): void
    {
        $this->prepare(['MAKE_TESTS' => '1']);
        $result = $this->build(['TEST_PHP_STATUS' => '7']);
        self::assertSame(7, $result['status'], $result['output']);
        $this->assertNoPackages();
    }

    public function testCanExplicitlyContinueAfterPhpTestFailures(): void
    {
        $this->prepare(['MAKE_TESTS' => '1', 'ABORT_ON_TEST_FAILURES' => '0']);
        $result = $this->build(['TEST_PHP_STATUS' => '7']);
        self::assertSame(0, $result['status'], $result['output']);
        self::assertStringContainsString('REPORT_EXIT_STATUS=0', file_get_contents($this->sandbox . '/test-calls'));
        self::assertFileExists($this->source . '/php-8.6.0.manifest');
    }

    public function testInvalidNewsAbortsBeforeTagging(): void
    {
        $this->prepare([], "PHP NEWS\n========\n01 Oct 2026, PHP 8.5.0\n");
        $result = $this->build();
        self::assertSame(1, $result['status']);
        self::assertStringContainsString('NEWS file has neither target release date', $result['output']);
        $this->assertNoPackages();
    }

    public function testAlreadyDatedNewsNeedsNoNewsCommit(): void
    {
        $this->prepare([], "PHP NEWS\n========\n08 Oct 2026, PHP 8.6.0\n");
        $result = $this->build();
        self::assertSame(0, $result['status'], $result['output']);
        self::assertSame("Add build fixtures\n", $this->gitOutput('log', '-1', '--format=%s'));
    }

    public function testCutsReleaseBranchAndUsesShallowClone(): void
    {
        $this->prepare(['CUT_RELEASE_BRANCH' => 'PHP-8.6.0', 'CLONE_DEPTH' => '5', 'RELEASE_VERSION' => '8.6.0RC1']);
        $result = $this->build();
        self::assertSame(0, $result['status'], $result['output']);
        self::assertSame("PHP-8.6.0\n", $this->gitOutput('branch', '--show-current'));
        self::assertStringContainsString('--depth=5', file_get_contents($this->sandbox . '/git-calls'));
        self::assertStringContainsString("git push upstream 'php-8.6.0RC1' 'PHP-8.6.0'", $result['output']);
        self::assertStringContainsString('downloads.php.net', $result['output']);
    }

    private function prepareUpmergeBranches(bool $conflict = false): void
    {
        $upstream = $this->sandbox . '/upstream/php-src';
        foreach (['PHP-8.7', 'PHP-8.8', 'master'] as $branch) {
            $this->succeed([$this->git, 'checkout', '-b', $branch], [], $upstream);
            $this->write($upstream . '/NEWS', "NEWS for $branch\n");
            $this->write($upstream . '/main/php_version.h', "version for $branch\n");
            $this->write($upstream . '/Zend/zend.h', "Zend for $branch\n");
            $this->write($upstream . '/configure.ac', "configure for $branch\n");
            if ($conflict) {
                $this->write($upstream . '/ext/standard/credits_ext.h', "conflicting credits\n");
            }
            $this->succeed([$this->git, 'add', '.'], [], $upstream);
            $this->succeed([$this->git, 'commit', '-m', "Prepare $branch"], [], $upstream);
        }
        $this->succeed([$this->git, 'checkout', 'PHP-8.6'], [], $upstream);
    }

    public function testUpmergesInOrderAndPreservesTargetVersionsAndNews(): void
    {
        $this->prepare([
            'UPMERGE_TO_MASTER' => 'yes',
            'RELEASE_NEXT' => '8.6.1',
            'PHP_REPO_FETCH' => 'file://' . $this->sandbox . '/upstream/php-src',
            'CLONE_DEPTH' => '1',
        ]);
        $this->prepareUpmergeBranches();
        $result = $this->build(['TEST_CREDITS_CHANGE' => '1']);
        self::assertSame(0, $result['status'], $result['output']);
        self::assertSame("false\n", $this->gitOutput('rev-parse', '--is-shallow-repository'));
        $previous = 'PHP-8.6';
        foreach (['PHP-8.7', 'PHP-8.8', 'master'] as $branch) {
            self::assertSame(trim($this->gitOutput('rev-parse', $previous)), trim($this->gitOutput('rev-parse', "$branch^2")));
            self::assertSame("NEWS for $branch\n", $this->gitOutput('show', "$branch:NEWS"));
            self::assertSame("version for $branch\n", $this->gitOutput('show', "$branch:main/php_version.h"));
            self::assertSame("Zend for $branch\n", $this->gitOutput('show', "$branch:Zend/zend.h"));
            self::assertSame("configure for $branch\n", $this->gitOutput('show', "$branch:configure.ac"));
            self::assertSame("updated credits\n", $this->gitOutput('show', "$branch:ext/standard/credits_ext.h"));
            self::assertStringContainsString("git push upstream '$branch'", $result['output']);
            $previous = $branch;
        }
        self::assertSame("PHP-8.6\n", $this->gitOutput('branch', '--show-current'));
        self::assertDoesNotMatchRegularExpression('/^push /m', file_get_contents($this->sandbox . '/git-calls'));
    }

    public function testUpmergesVersionBranchAfterCuttingRc5Branch(): void
    {
        $this->prepare([
            'UPMERGE_TO_MASTER' => 'yes',
            'CUT_RELEASE_BRANCH' => 'PHP-8.6.0',
            'RELEASE_VERSION' => '8.6.0RC5',
            'RELEASE_NEXT' => '8.6.0',
        ]);
        $this->prepareUpmergeBranches();
        $result = $this->build();
        self::assertSame(0, $result['status'], $result['output']);
        self::assertSame("PHP-8.6.0\n", $this->gitOutput('branch', '--show-current'));
        self::assertSame($this->gitOutput('rev-parse', 'PHP-8.6'), $this->gitOutput('rev-parse', 'PHP-8.7^2'));
        self::assertStringContainsString('#define PHP_VERSION "8.6.1-dev"', $this->gitOutput('show', 'PHP-8.6:main/php_version.h'));
        self::assertStringStartsWith("PHP NEWS\n========\n?? ??? ????, PHP 8.6.0\n", file_get_contents($this->source . '/NEWS'));
    }

    public function testPatchReleaseUpmergesThroughItsVersionBranch(): void
    {
        $this->prepare([
            'UPMERGE_TO_MASTER' => 'yes',
            'RELEASE_BRANCH' => 'PHP-8.6.0',
            'RELEASE_NEXT' => '8.6.1',
        ]);
        $this->prepareUpmergeBranches();
        $upstream = $this->sandbox . '/upstream/php-src';
        $this->succeed([$this->git, 'branch', 'PHP-8.6.0'], [], $upstream);
        $this->write($upstream . '/NEWS', "Version branch NEWS\n");
        $this->succeed([$this->git, 'add', 'NEWS'], [], $upstream);
        $this->succeed([$this->git, 'commit', '-m', 'Advance version branch'], [], $upstream);
        $result = $this->build(['TEST_CREDITS_CHANGE' => '1']);
        self::assertSame(0, $result['status'], $result['output']);
        $previous = 'PHP-8.6.0';
        foreach (['PHP-8.6', 'PHP-8.7', 'PHP-8.8', 'master'] as $branch) {
            self::assertSame($this->gitOutput('rev-parse', $previous), $this->gitOutput('rev-parse', "$branch^2"));
            self::assertSame("updated credits\n", $this->gitOutput('show', "$branch:ext/standard/credits_ext.h"));
            $previous = $branch;
        }
        self::assertSame("Version branch NEWS\n", $this->gitOutput('show', 'PHP-8.6:NEWS'));
        self::assertSame("PHP-8.6.0\n", $this->gitOutput('branch', '--show-current'));
    }

    public function testMasterReleaseWithUpmergeEnabledNeedsNoOtherBranches(): void
    {
        $this->prepare([
            'UPMERGE_TO_MASTER' => 'yes',
            'RELEASE_BRANCH' => 'master',
            'RELEASE_VERSION' => '8.7.0alpha1',
            'CUT_RELEASE_BRANCH' => 'php-8.7.0alpha1-local-release-branch',
            'RELEASE_NEXT' => '8.7.0alpha2',
        ]);
        $upstream = $this->sandbox . '/upstream/php-src';
        $this->succeed([$this->git, 'branch', '-m', 'master'], [], $upstream);
        $result = $this->build();
        self::assertSame(0, $result['status'], $result['output']);
        self::assertSame("upstream/master\n", $this->gitOutput('branch', '-r', '--format=%(refname:short)'));
        self::assertSame("php-8.7.0alpha1-local-release-branch\n", $this->gitOutput('branch', '--show-current'));
        self::assertStringNotContainsString('Review the upmerge', $result['output']);
    }

    public function testUpmergeStopsOnOtherConflicts(): void
    {
        $this->prepare(['UPMERGE_TO_MASTER' => 'yes']);
        $this->prepareUpmergeBranches(true);
        $result = $this->build(['TEST_CREDITS_CHANGE' => '1']);
        self::assertNotSame(0, $result['status']);
        self::assertStringContainsString('resolve them and continue manually', $result['output']);
        self::assertSame("PHP-8.7\n", $this->gitOutput('branch', '--show-current'));
        self::assertSame("ext/standard/credits_ext.h\n", $this->gitOutput('diff', '--name-only', '--diff-filter=U'));
        self::assertSame($this->gitOutput('rev-parse', 'upstream/master'), $this->gitOutput('rev-parse', 'master'));
        self::assertStringNotContainsString('Tarballs prepared', $result['output']);
    }

    public function testUpmergeDefaultsToNo(): void
    {
        $this->prepare();
        $this->prepareUpmergeBranches();
        $result = $this->build();
        self::assertSame(0, $result['status'], $result['output']);
        self::assertSame("PHP-8.6\n", $this->gitOutput('branch', '--format=%(refname:short)'));
    }

    public function testInvalidUpmergeSettingFailsBeforeCloning(): void
    {
        $this->prepare(['UPMERGE_TO_MASTER' => 'maybe']);
        $result = $this->build();
        self::assertNotSame(0, $result['status']);
        self::assertStringContainsString('UPMERGE_TO_MASTER must be yes or no', $result['output']);
        self::assertDirectoryDoesNotExist($this->source);
    }

    public static function branchWarnings(): array
    {
        return [
            'stable warns' => ['8.7.0', true],
            'RC warns' => ['8.7.0RC1', true],
            'alpha may use earlier branch' => ['8.7.0alpha1', false],
            'beta may use earlier branch' => ['8.7.0beta1', false],
        ];
    }

    public static function nextVersionsWhenCuttingBranch(): array
    {
        return [
            'automatic next patch' => [null],
            'next RC on cut branch' => ['8.6.0RC6'],
            'GA on cut branch' => ['8.6.0'],
        ];
    }

    #[DataProvider('nextVersionsWhenCuttingBranch')]
    public function testCuttingPatchBranchPreparesVersionBranch(?string $next): void
    {
        $this->prepare([
            'CUT_RELEASE_BRANCH' => 'PHP-8.6.0',
            'RELEASE_VERSION' => '8.6.0RC5',
            'RELEASE_NEXT' => $next,
        ]);
        $result = $this->build();
        self::assertSame(0, $result['status'], $result['output']);
        self::assertSame("PHP-8.6.0\n", $this->gitOutput('branch', '--show-current'));
        self::assertStringStartsWith("PHP NEWS\n========\n?? ??? ????, PHP 8.6.1\n", $this->gitOutput('show', 'PHP-8.6:NEWS'));
        self::assertStringContainsString('08 Oct 2026, PHP 8.6.0RC5', $this->gitOutput('show', 'PHP-8.6:NEWS'));
        self::assertStringContainsString('#define PHP_VERSION "8.6.1-dev"', $this->gitOutput('show', 'PHP-8.6:main/php_version.h'));
        self::assertStringContainsString('[8.6.1-dev]', $this->gitOutput('show', 'PHP-8.6:configure.ac'));
        if ($next !== null) {
            self::assertStringStartsWith("PHP NEWS\n========\n?? ??? ????, PHP {$next}\n", $this->gitOutput('show', 'PHP-8.6.0:NEWS'));
        }
        self::assertStringContainsString('#define ZEND_VERSION "4.6.1-dev"', $this->gitOutput('show', 'PHP-8.6:Zend/zend.h'));
        if ($next === '8.6.0') {
            self::assertStringContainsString('#define PHP_VERSION "8.6.0-dev"', $this->gitOutput('show', 'PHP-8.6.0:main/php_version.h'));
            self::assertStringContainsString('#define ZEND_VERSION "4.6.0-dev"', $this->gitOutput('show', 'PHP-8.6.0:Zend/zend.h'));
        }
        self::assertStringNotContainsString('PHP 8.6.1', $this->gitOutput('show', 'PHP-8.6.0:NEWS'));
        self::assertStringStartsWith("PHP NEWS\n========\n08 Oct 2026, PHP 8.6.0RC5\n", $this->gitOutput('show', 'php-8.6.0RC5:NEWS'));
        self::assertStringContainsString('#define PHP_VERSION "8.6.0RC5"', $this->gitOutput('show', 'php-8.6.0RC5:main/php_version.h'));
        self::assertSame("PHP-8.6 is now 8.6.1-dev\n", $this->gitOutput('log', '-1', '--format=%s', 'PHP-8.6'));
        self::assertStringContainsString("git push upstream 'PHP-8.6'", $result['output']);
        self::assertStringNotContainsString('2a. Bump version', $result['output']);
    }

    #[DataProvider('branchWarnings')]
    public function testWarnsForUnexpectedReleaseBranch(string $version, bool $warns): void
    {
        $this->prepare(['RELEASE_VERSION' => $version]);
        $result = $this->build();
        self::assertSame(0, $result['status'], $result['output']);
        self::assertSame($warns, str_contains($result['output'], '** WARNING:'));
        if ($warns) {
            self::assertSame("15\n", file_get_contents($this->sandbox . '/sleep-calls'));
        } else {
            self::assertFileDoesNotExist($this->sandbox . '/sleep-calls');
        }
    }

    public function testUpdatesCreditsAndApiNumbers(): void
    {
        $this->prepare(['BUMP_API' => '1']);
        $result = $this->build(['TEST_CREDITS_CHANGE' => '1']);
        self::assertSame(0, $result['status'], $result['output']);
        self::assertSame("#define PHP_API_VERSION 20261008\n", file_get_contents($this->source . '/main/php.h'));
        self::assertSame("#define ZEND_EXTENSION_API_NO 420261008\n", file_get_contents($this->source . '/Zend/zend_extensions.h'));
        self::assertSame("#define ZEND_MODULE_API_NO 20261008\n", file_get_contents($this->source . '/Zend/zend_modules.h'));
        self::assertSame("updated credits\n", file_get_contents($this->source . '/ext/standard/credits_ext.h'));
        self::assertStringContainsString('Prepare for PHP 8.6.0', $this->gitOutput('log', '--format=%s'));
        self::assertStringContainsString('Update CREDITS for PHP 8.6.0', $this->gitOutput('log', '--format=%s'));
    }

    public function testPreparesNextPrereleaseNewsAndRestoresReleaseVersion(): void
    {
        $this->prepare(['RELEASE_VERSION' => '8.6.0RC1', 'RELEASE_NEXT' => '8.6.0RC2']);
        $result = $this->build();
        self::assertSame(0, $result['status'], $result['output']);
        self::assertStringContainsString('?? ??? ????, PHP 8.6.0RC2', file_get_contents($this->source . '/NEWS'));
        self::assertSame("[ci skip] Update NEWS for 8.6.0RC2\n", $this->gitOutput('log', '-1', '--format=%s'));
        self::assertSame("old version\n", file_get_contents($this->source . '/main/php_version.h'));
        self::assertStringContainsString("git push upstream 'php-8.6.0RC1'", $result['output']);
    }

    public function testPackagingFailureStopsBeforeResettingBranch(): void
    {
        $this->prepare();
        $result = $this->build(['TEST_PACKAGE_STATUS' => '9']);
        self::assertSame(9, $result['status'], $result['output']);
        self::assertSame($this->gitOutput('rev-parse', 'HEAD'), $this->gitOutput('rev-parse', 'php-8.6.0^{}'));
        self::assertFileDoesNotExist($this->source . '/php-8.6.0.manifest');
        self::assertStringNotContainsString('Tarballs prepared', $result['output']);
    }

    public static function failedBuildCommands(): array
    {
        return [
            'buildconf' => ['TEST_BUILDCONF_STATUS'],
            'configure' => ['TEST_CONFIGURE_STATUS'],
            'make' => ['TEST_MAKE_STATUS'],
        ];
    }

    #[DataProvider('failedBuildCommands')]
    public function testBuildCommandFailureAbortsBeforeTagging(string $variable): void
    {
        $this->prepare(['MAKE_TESTS' => '1']);
        $result = $this->build([$variable => '6']);
        self::assertSame(6, $result['status'], $result['output']);
        self::assertFileDoesNotExist($this->sandbox . '/test-calls');
        $this->assertNoPackages();
    }

    public function testPreparesConsistentNextDevelopmentVersion(): void
    {
        $this->prepare(['RELEASE_NEXT' => '8.6.1']);
        $result = $this->build();
        self::assertSame(0, $result['status'], $result['output']);
        $header = file_get_contents($this->source . '/main/php_version.h');
        self::assertStringContainsString('#define PHP_EXTRA_VERSION "-dev"', $header);
        self::assertStringContainsString('#define PHP_VERSION "8.6.1-dev"', $header);
        self::assertStringContainsString('#define PHP_VERSION_ID 80601', $header);
        self::assertStringContainsString('[8.6.1-dev]', file_get_contents($this->source . '/configure.ac'));
        self::assertStringContainsString('#define PHP_VERSION "8.6.0"', $this->gitOutput('show', 'php-8.6.0:main/php_version.h'));
        self::assertStringContainsString("git push upstream 'php-8.6.0'", $result['output']);
    }
}
