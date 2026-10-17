<?php

declare(strict_types=1);

namespace App\Tests\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Two monolog handlers writing to one file is a silent duplicate-logging bug.
 *
 * ⚠️ Handlers are independent: the logger offers every record to each of them,
 * so `main`'s fingers_crossed buffer and the always-on `warnings` handler both
 * wrote each warning to prod.log once an error tripped the buffer — the same
 * entry twice, microsecond timestamp included. Nothing fails, nothing is lost,
 * and `grep -c` quietly reports double. Found in Campaigner (its PR #86) and
 * mirrored here, since the two prod configs are deliberately twins; the guard
 * is a config test because no runtime test loads the prod handler stack.
 *
 * Channel filters can make two same-path handlers harmless in a given moment
 * (the deprecation handler was), but that is a property of the `channels` lists
 * rather than of the paths — so the rule enforced here is the blunt one.
 */
final class MonologHandlerPathsTest extends TestCase
{
    /**
     * @return array<string, array<string, mixed>>
     */
    private static function config(): array
    {
        /** @var array<string, array<string, mixed>> $parsed */
        $parsed = Yaml::parseFile(dirname(__DIR__, 2) . '/config/packages/monolog.yaml');

        return $parsed;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function handlers(string $envBlock): array
    {
        $config = self::config();
        self::assertArrayHasKey($envBlock, $config, "Missing {$envBlock} block");

        /** @var array<string, array<string, mixed>> $handlers */
        $handlers = $config[$envBlock]['monolog']['handlers'] ?? [];
        self::assertNotSame([], $handlers, "No handlers under {$envBlock}");

        return $handlers;
    }

    /**
     * @return list<array{string}>
     */
    public static function envBlocks(): array
    {
        return [['when@dev'], ['when@test'], ['when@prod']];
    }

    #[DataProvider('envBlocks')]
    public function testNoTwoHandlersWriteToTheSameFile(string $envBlock): void
    {
        $byPath = [];
        foreach (self::handlers($envBlock) as $name => $handler) {
            $path = $handler['path'] ?? null;
            if (!is_string($path)) {
                continue;
            }
            $byPath[$path][] = $name;
        }

        foreach ($byPath as $path => $names) {
            self::assertCount(
                1,
                $names,
                sprintf(
                    'Handlers %s in %s all write to %s — every record they share lands there once per handler.',
                    implode(', ', $names),
                    $envBlock,
                    $path
                )
            );
        }
    }

    /**
     * The load-bearing half (nouriloop-ops docs/ops/production.md, "Logging (prod)"): warning+ must reach
     * prod.log on a run that never errors — that is where the duplicate-meal
     * warning and every other degraded-but-not-broken signal lands. Splitting
     * the destinations to kill the duplication must not move the warning stream
     * out of prod.log.
     */
    public function testWarningsStillLandInProdLog(): void
    {
        $warnings = self::handlers('when@prod')['warnings'] ?? null;

        self::assertIsArray($warnings, 'The always-on warnings handler is gone');
        self::assertSame('%kernel.logs_dir%/%kernel.environment%.log', $warnings['path'] ?? null);
        self::assertSame('warning', $warnings['level'] ?? null);
        self::assertSame('stream', $warnings['type'] ?? null);
    }
}
