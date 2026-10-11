<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SeedDemoUserCommand;
use App\Service\DemoDataSeeder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * The demo user has a published password and a permanent Loop+ comp, so the
 * seeder must never run in prod; dev (the local review account) and test
 * (help screenshots) are its intended homes.
 */
final class SeedDemoUserCommandTest extends TestCase
{
    public function testRefusesInProdWithoutSeeding(): void
    {
        $seeder = $this->createMock(DemoDataSeeder::class);
        $seeder->expects(self::never())->method('seed');

        $tester = new CommandTester(new SeedDemoUserCommand($seeder, $this->kernel('prod')));
        $tester->execute([]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Refusing', $tester->getDisplay());
    }

    public function testSeedsInDev(): void
    {
        $seeder = $this->createMock(DemoDataSeeder::class);
        $seeder->expects(self::once())->method('seed')->willReturn(['meals' => 3]);

        $tester = new CommandTester(new SeedDemoUserCommand($seeder, $this->kernel('dev')));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
    }

    private function kernel(string $env): KernelInterface
    {
        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getEnvironment')->willReturn($env);

        return $kernel;
    }
}
