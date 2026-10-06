<?php

declare(strict_types=1);

namespace PhpRelease\Tests;

use PHPUnit\Framework\Attributes\DataProvider;

final class ParseVersionTest extends ScriptTestCase
{
    public static function versions(): array
    {
        return [
            'stable' => ['8.6.0', '8', '6', '0', ''],
            'patch release' => ['8.5.12', '8', '5', '12', ''],
            'alpha' => ['8.6.0alpha1', '8', '6', '0', 'alpha1'],
            'beta' => ['8.6.0beta2', '8', '6', '0', 'beta2'],
            'release candidate' => ['8.6.0RC3', '8', '6', '0', 'RC3'],
            'multi-digit candidate' => ['8.6.12RC10', '8', '6', '12', 'RC10'],
            'development' => ['8.6.1-dev', '8', '6', '1', '-dev'],
        ];
    }

    #[DataProvider('versions')]
    public function testExportsVersionComponents(string $version, string $major, string $minor, string $patch, string $extra): void
    {
        $output = $this->succeed(['bash', '-c', <<<'BASH'
source "$1" "$2"
printf '%s\n' "$RELEASE_VERSION" "$VERSION_MAJOR" "$VERSION_MINOR" "$VERSION_PATCH" "$VERSION_EXTRA"
BASH, 'test', $this->sandbox . '/parse-version.sh', $version]);
        self::assertSame(implode("\n", [$version, $major, $minor, $patch, $extra]) . "\n", $output);
    }

    public function testMissingVersionFailsWithUsage(): void
    {
        $result = $this->execute(['bash', $this->sandbox . '/parse-version.sh']);
        self::assertSame(1, $result['status']);
        self::assertStringContainsString('<release-version>', $result['output']);
    }

    public function testParsingStableVersionClearsPreviousSuffix(): void
    {
        $output = $this->succeed(['bash', '-c', <<<'BASH'
source "$1" 8.6.0RC1
source "$1" 8.6.0
printf '<%s>\n' "$VERSION_EXTRA"
BASH, 'test', $this->sandbox . '/parse-version.sh']);
        self::assertSame("<>\n", $output);
    }
}
