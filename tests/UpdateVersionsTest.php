<?php

declare(strict_types=1);

namespace PhpRelease\Tests;

use PHPUnit\Framework\Attributes\DataProvider;

final class UpdateVersionsTest extends ScriptTestCase
{
    public static function versions(): array
    {
        return [
            'stable' => ['8.6.0', '80600', '4.6.0'],
            'patch' => ['8.5.12', '80512', '4.5.12'],
            'RC' => ['8.6.0RC2', '80600', '4.6.0RC2'],
            'alpha keeps Zend version' => ['8.6.0alpha1', '80600', '4.5.0'],
            'beta keeps Zend version' => ['8.6.0beta2', '80600', '4.5.0'],
            'development' => ['8.6.1-dev', '80601', '4.6.1-dev'],
        ];
    }

    private function update(string $version, array $environment = []): void
    {
        $this->succeed(['bash', '-c', <<<'BASH'
source "$1" "$3"
source "$2"
BASH, 'test', $this->sandbox . '/parse-version.sh', $this->sandbox . '/update-versions.sh', $version],
            array_merge(['CONFIGURE_AC' => 'configure.ac'], $environment));
    }

    #[DataProvider('versions')]
    public function testUpdatesVersionFilesAndStagesChanges(string $version, string $id, string $zend): void
    {
        $this->fixture($this->source);
        $this->update($version);
        $header = file_get_contents($this->source . '/main/php_version.h');
        self::assertStringContainsString('#define PHP_VERSION "' . $version . '"', $header);
        self::assertStringContainsString('#define PHP_VERSION_ID ' . $id . "\n", $header);
        self::assertSame('#define ZEND_VERSION "' . $zend . '"' . "\n", file_get_contents($this->source . '/Zend/zend.h'));
        self::assertSame('AC_INIT([PHP],[' . $version . '], [https://github.com/php/php-src/issues])' . "\n",
            file_get_contents($this->source . '/configure.ac'));
        $staged = $this->succeed([$this->git, 'diff', '--cached', '--name-only'], [], $this->source);
        self::assertStringContainsString('main/php_version.h', $staged);
        self::assertStringContainsString('configure.ac', $staged);
        if ($zend === '4.5.0') {
            self::assertStringNotContainsString('Zend/zend.h', $staged);
        } else {
            self::assertStringContainsString('Zend/zend.h', $staged);
        }
        self::assertSame('', $this->succeed([$this->git, 'diff'], [], $this->source));
    }

    public function testUsesExplicitZendVersionAndAlternateConfigureFile(): void
    {
        $this->fixture($this->source);
        $this->write($this->source . '/configure.in', "AC_INIT([PHP],[8.5.0],[bugs@example.com])\n");
        $this->update('8.6.0', ['ZEND_VERSION' => '99.1.2', 'CONFIGURE_AC' => 'configure.in']);
        self::assertSame("#define ZEND_VERSION \"99.1.2\"\n", file_get_contents($this->source . '/Zend/zend.h'));
        self::assertSame("AC_INIT([PHP],[8.6.0],[bugs@example.com])\n", file_get_contents($this->source . '/configure.in'));
        self::assertStringContainsString('[8.6.0-dev]', file_get_contents($this->source . '/configure.ac'));
    }

    public function testDevelopmentSuffixDoesNotChangeReleaseIdentifier(): void
    {
        $this->fixture($this->source);
        $output = $this->succeed(['bash', '-c', <<<'BASH'
source "$1" 8.6.1
VERSION_EXTRA=-dev
source "$2"
printf '%s\n' "$RELEASE_VERSION"
BASH, 'test', $this->sandbox . '/parse-version.sh', $this->sandbox . '/update-versions.sh'],
            ['CONFIGURE_AC' => 'configure.ac']);
        self::assertSame("8.6.1\n", $output);
        self::assertStringContainsString('#define PHP_VERSION "8.6.1-dev"', file_get_contents($this->source . '/main/php_version.h'));
        self::assertStringContainsString('[8.6.1-dev]', file_get_contents($this->source . '/configure.ac'));
    }

    public function testPreservesExplicitZendVersionWhenSourcedAgain(): void
    {
        $this->fixture($this->source);
        $this->succeed(['bash', '-c', <<<'BASH'
source "$1" 8.6.0
source "$2"
source "$1" 8.6.1-dev
source "$2"
BASH, 'test', $this->sandbox . '/parse-version.sh', $this->sandbox . '/update-versions.sh'],
            ['CONFIGURE_AC' => 'configure.ac', 'ZEND_VERSION' => '99.1.2']);
        self::assertSame("#define ZEND_VERSION \"99.1.2\"\n", file_get_contents($this->source . '/Zend/zend.h'));
    }

    public static function missingComponents(): array
    {
        return [['VERSION_MAJOR'], ['VERSION_MINOR'], ['VERSION_PATCH']];
    }

    #[DataProvider('missingComponents')]
    public function testRejectsMissingVersionComponents(string $missing): void
    {
        $environment = ['VERSION_MAJOR' => '8', 'VERSION_MINOR' => '6', 'VERSION_PATCH' => '0'];
        unset($environment[$missing]);
        $result = $this->execute(['bash', $this->sandbox . '/update-versions.sh'], $environment);
        self::assertNotSame(0, $result['status']);
        self::assertStringContainsString($missing . ' is not set', $result['output']);
        self::assertDirectoryDoesNotExist($this->source);
    }
}
