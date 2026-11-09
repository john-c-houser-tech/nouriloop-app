<?php

declare(strict_types=1);

namespace App\Tests\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * scripts/check-symfony-line.php, which CI runs before `composer install`: every Symfony
 * framework component in composer.lock stays on composer.json's extra.symfony.require.
 * Dependabot doesn't apply that setting, and its grouped bump in 24f2559 moved 14
 * indirect components to 8.1, which then blocked a local security update.
 *
 * Also: every indirect framework component carries a `conflict` entry in composer.json,
 * which is what stops Composer (and so Dependabot) resolving it off the line.
 */
final class SymfonyLineCheckTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/symfony-line-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (['composer.json', 'composer.lock'] as $file) {
            @unlink($this->dir . '/' . $file);
        }
        @rmdir($this->dir);
    }

    public function testTheProjectLockIsOnTheLine(): void
    {
        $line = self::project()['composer']['extra']['symfony']['require'];

        [$code, $out] = $this->runCheck(self::root());

        self::assertSame(0, $code, $out);
        self::assertStringContainsString("Symfony components on {$line}", $out);
    }

    public function testEveryIndirectComponentHasAConflictEntryOffTheLine(): void
    {
        ['composer' => $composer, 'lock' => $lock] = self::project();
        [$major] = explode('.', $composer['extra']['symfony']['require']);
        $direct = array_merge($composer['require'], $composer['require-dev'] ?? []);
        $conflicts = $composer['conflict'] ?? [];

        $missing = [];
        foreach (array_merge($lock['packages'], $lock['packages-dev']) as $package) {
            $name = $package['name'];
            if (!self::isFrameworkComponent($name) || isset($direct[$name])) {
                continue;
            }
            if (($conflicts[$name] ?? null) !== '>=' . ((int) $major + 1) . '.0') {
                $missing[] = $name;
            }
        }

        self::assertSame([], $missing, 'Add `"<name>": ">=' . ((int) $major + 1) . '.0"` to composer.json conflict');
    }

    public function testComponentsOnTheLinePassAndOwnVersionedPackagesAreSkipped(): void
    {
        $this->write('7.4.*', [
            ['symfony/http-foundation', 'v7.4.23'],
            ['symfony/phpunit-bridge', 'v7.4.22'],
            ['symfony/flex', 'v2.12.0'],
            ['symfony/service-contracts', 'v3.7.3'],
            ['symfony/contracts', 'v3.7.3'],
            ['symfony/polyfill-mbstring', 'v1.43.3'],
            ['symfony/ux-turbo', 'v3.6.0'],
            ['symfony/stimulus-bundle', 'v3.6.0'],
            ['symfony/monolog-bundle', 'v4.1.0'],
            ['symfony/maker-bundle', '1.68.0'],
            ['symfony/panther', 'v2.4.0'],
            ['twig/twig', 'v3.29.0'],
        ]);

        [$code, $out] = $this->runCheck($this->dir);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('OK: 2 Symfony components on 7.4.*', $out);
    }

    public function testAComponentOffTheLineFailsAndIsNamed(): void
    {
        $this->write('7.4.*', [
            ['symfony/http-foundation', 'v8.1.11'],
            ['symfony/routing', 'v7.4.23'],
        ], [['symfony/var-dumper', 'v8.1.10']]);

        [$code, $out] = $this->runCheck($this->dir);

        self::assertSame(1, $code, $out);
        self::assertStringContainsString('symfony/http-foundation v8.1.11', $out);
        self::assertStringContainsString('symfony/var-dumper v8.1.10', $out, 'packages-dev are checked too');
        self::assertStringNotContainsString('symfony/routing', $out);
    }

    public function testALockWithoutPackagesDevIsChecked(): void
    {
        $this->write('7.4.*', [['symfony/routing', 'v7.4.23']], null);

        [$code, $out] = $this->runCheck($this->dir);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('OK: 1 Symfony components', $out);
    }

    public function testALineIsMatchedWholeNotByPrefix(): void
    {
        $this->write('7.4.*', [['symfony/routing', 'v7.40.1']]);

        self::assertSame(1, $this->runCheck($this->dir)[0]);
    }

    public function testAnUnknownSymfonyPackageFailsInsteadOfSlippingThrough(): void
    {
        $this->write('7.4.*', [['symfony/ai-bundle', 'v0.3.0']]);

        [$code, $out] = $this->runCheck($this->dir);

        self::assertSame(1, $code);
        self::assertStringContainsString('symfony/ai-bundle v0.3.0', $out);
    }

    #[DataProvider('unusableLines')]
    public function testAnUnusableLineSettingIsAnError(?string $line): void
    {
        $this->write($line, [['symfony/routing', 'v7.4.23']]);

        [$code, $out] = $this->runCheck($this->dir);

        self::assertSame(2, $code, $out);
        self::assertStringContainsString('must look like "7.4.*"', $out);
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function unusableLines(): iterable
    {
        yield 'not set' => [null];
        yield 'caret range' => ['^7.4'];
        yield 'no wildcard' => ['7.4'];
        yield 'major only' => ['7.*'];
        yield 'trailing text' => ['7.4.* || 8.1.*'];
        yield 'trailing newline' => ["7.4.*\n"];
    }

    public function testNothingToCheckIsAnErrorNotAPass(): void
    {
        $this->write('7.4.*', [['twig/twig', 'v3.29.0'], ['symfony/flex', 'v2.12.0']]);

        [$code, $out] = $this->runCheck($this->dir);

        self::assertSame(2, $code, $out);
        self::assertStringContainsString('nothing was checked', $out);
    }

    public function testAMissingLockIsAnError(): void
    {
        $this->write('7.4.*', []);
        unlink($this->dir . '/composer.lock');

        [$code, $out] = $this->runCheck($this->dir);

        self::assertSame(2, $code, $out);
        self::assertStringContainsString('Cannot read', $out);
    }

    public function testAMalformedLockEntryIsAnErrorNotACrash(): void
    {
        $this->write('7.4.*', []);
        file_put_contents($this->dir . '/composer.lock', json_encode(['packages' => [['name' => 'symfony/routing']]]));

        [$code, $out] = $this->runCheck($this->dir);

        self::assertSame(2, $code, $out);
        self::assertStringContainsString('has no name or version', $out);
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return array{composer: array<string, mixed>, lock: array<string, mixed>}
     */
    private static function project(): array
    {
        $read = static fn (string $file): array => json_decode(
            (string) file_get_contents(self::root() . '/' . $file),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return ['composer' => $read('composer.json'), 'lock' => $read('composer.lock')];
    }

    /** The same skip list as the script: Symfony packages versioned on their own. */
    private static function isFrameworkComponent(string $name): bool
    {
        return str_starts_with($name, 'symfony/')
            && preg_match('#^symfony/(flex|([a-z-]+-)?contracts|polyfill-.*|ux-.*|stimulus-bundle|monolog-bundle'
                . '|maker-bundle|panther)$#', $name) !== 1;
    }

    /**
     * @param list<array{string, string}>      $packages
     * @param list<array{string, string}>|null $devPackages null leaves the key out
     */
    private function write(?string $line, array $packages, ?array $devPackages = []): void
    {
        $toLock = static fn (array $list): array => array_map(
            static fn (array $p): array => ['name' => $p[0], 'version' => $p[1]],
            $list,
        );
        $extra = $line === null ? [] : ['extra' => ['symfony' => ['require' => $line]]];
        file_put_contents($this->dir . '/composer.json', json_encode($extra));
        $lock = ['packages' => $toLock($packages)];
        if ($devPackages !== null) {
            $lock['packages-dev'] = $toLock($devPackages);
        }
        file_put_contents($this->dir . '/composer.lock', json_encode($lock));
    }

    /**
     * @return array{int, string} exit code, stdout + stderr
     */
    private function runCheck(string $project): array
    {
        $process = proc_open(
            [PHP_BINARY, self::root() . '/scripts/check-symfony-line.php', $project],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out];
    }
}
