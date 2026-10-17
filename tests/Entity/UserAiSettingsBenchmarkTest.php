<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Ai\UserAiProvider;
use App\Entity\User;
use App\Entity\UserAiSettings;
use PHPUnit\Framework\TestCase;

final class UserAiSettingsBenchmarkTest extends TestCase
{
    public function testThePhotoModelFallsBackToTheTextModelThenTheProviderDefault(): void
    {
        $settings = new UserAiSettings($this->createStub(User::class), UserAiProvider::OpenRouter);
        self::assertSame(UserAiProvider::OpenRouter->defaultModel(), $settings->effectiveVisionModel());

        $settings->setTextModel('text-m');
        self::assertSame('text-m', $settings->effectiveVisionModel());

        $settings->setVisionModel('vision-m');
        self::assertSame('vision-m', $settings->effectiveVisionModel());
    }

    public function testTheVerdictWaitsForEveryCaseThenFailsOnAnyMiss(): void
    {
        $settings = (new UserAiSettings($this->createStub(User::class), UserAiProvider::Anthropic))->resetBenchmark();
        self::assertNull($settings->benchmarkPassed(3));

        $settings->recordBenchmarkCase(['name' => 'a', 'passed' => true])
            ->recordBenchmarkCase(['name' => 'b', 'passed' => true]);
        self::assertNull($settings->benchmarkPassed(3), 'two of three is not a verdict');

        $settings->recordBenchmarkCase(['name' => 'c', 'passed' => false]);
        self::assertFalse($settings->benchmarkPassed(3));

        $settings->recordBenchmarkCase(['name' => 'c', 'passed' => true]);
        self::assertTrue($settings->benchmarkPassed(3), 're-running a case replaces its result');
    }

    public function testResultsForAnotherPhotoModelAreStale(): void
    {
        $settings = (new UserAiSettings($this->createStub(User::class), UserAiProvider::Anthropic))->resetBenchmark();
        $settings->recordBenchmarkCase(['name' => 'a', 'passed' => true]);
        self::assertTrue($settings->benchmarkPassed(1));

        $settings->setVisionModel('another-model');
        self::assertSame([], $settings->getBenchmarkResults());
        self::assertNull($settings->benchmarkPassed(1));
    }
}
