<?php

declare(strict_types=1);

namespace App\Tests\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * scripts/check-symfony-line.php, which CI runs after `composer install`: every Symfony
 * framework component in composer.lock stays on composer.json's extra.symfony.require.
 * Dependabot doesn't apply that setting, and its grouped bump in 24f2559 moved 14
 * indirect components to 8.1, which then blocked a local security update.
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
        [$code, $out] = $this->runCheck(dirname(__DIR__, 2));

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Symfony components on 7.4.*', $out);
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
    public function testAnUnusableLineSettingIsAnError(string $line): void
    {
        $this->write($line, [['symfony/routing', 'v7.4.23']]);

        [$code, $out] = $this->runCheck($this->dir);

        self::assertSame(2, $code, $out);
        self::assertStringContainsString('must look like "7.4.*"', $out);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableLines(): iterable
    {
        yield 'caret range' => ['^7.4'];
        yield 'no wildcard' => ['7.4'];
        yield 'major only' => ['7.*'];
        yield 'trailing text' => ['7.4.* || 8.1.*'];
    }

    /**
     * @param list<array{string, string}> $packages
     * @param list<array{string, string}> $devPackages
     */
    private function write(string $line, array $packages, array $devPackages = []): void
    {
        $toLock = static fn (array $list): array => array_map(
            static fn (array $p): array => ['name' => $p[0], 'version' => $p[1]],
            $list,
        );
        file_put_contents($this->dir . '/composer.json', json_encode(
            ['extra' => ['symfony' => ['require' => $line]]],
        ));
        file_put_contents($this->dir . '/composer.lock', json_encode(
            ['packages' => $toLock($packages), 'packages-dev' => $toLock($devPackages)],
        ));
    }

    /**
     * @return array{int, string} exit code, stdout + stderr
     */
    private function runCheck(string $project): array
    {
        $script = dirname(__DIR__, 2) . '/scripts/check-symfony-line.php';
        $process = proc_open(
            [PHP_BINARY, $script, $project],
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
