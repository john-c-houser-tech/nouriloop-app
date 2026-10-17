<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\AiCostsCommand;
use App\Hosting\Brand;
use App\Hosting\HostingMode;
use App\Repository\AiCallRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AiCostsCommandTest extends TestCase
{
    public function testReportsEachModelAndATotalForTheMonthAsked(): void
    {
        $calls = $this->createMock(AiCallRepository::class);
        $calls->expects(self::once())->method('totals')
            ->with(new \DateTimeImmutable('2026-07-01 00:00:00'), new \DateTimeImmutable('2026-08-01 00:00:00'))
            ->willReturn([
                $this->row('meal_analysis', 'claude-sonnet-4-6', 65, 1_310_000),
                $this->row('help_assistant', 'claude-haiku-4-5-20251001', 4, 10_800),
            ]);

        $tester = new CommandTester(new AiCostsCommand($calls, self::brand()));
        self::assertSame(Command::SUCCESS, $tester->execute(['--month' => '2026-07']));

        $out = $tester->getDisplay();
        self::assertStringContainsString('AI calls, July 2026', $out);
        self::assertStringContainsString('meal_analysis', $out);
        self::assertStringContainsString('$1.3100', $out);
        self::assertMatchesRegularExpression('/NouriLoop total\s.*69\s.*\$1\.3208/', $out);
        self::assertStringNotContainsString('no listed price', $out);
    }

    public function testUnpricedCallsAreFlaggedNotSilentlyTotalled(): void
    {
        $calls = $this->createStub(AiCallRepository::class);
        $calls->method('totals')->willReturn([$this->row('meal_analysis', 'qwen2.5vl', 3, 0, unpriced: 3)]);

        $tester = new CommandTester(new AiCostsCommand($calls, self::brand()));
        $tester->execute(['--month' => '2026-10']);

        self::assertStringContainsString('n/a', $tester->getDisplay());
        self::assertStringContainsString('3 call(s) used a model with no listed price', $tester->getDisplay());
    }

    public function testCallsPaidWithAUsersOwnKeyAreListedButKeptOutOfNouriLoopsTotal(): void
    {
        $calls = $this->createStub(AiCallRepository::class);
        $calls->method('totals')->willReturn([
            $this->row('meal_analysis', 'claude-sonnet-4-6', 10, 200_000),
            $this->row('meal_analysis', 'claude-sonnet-4-6', 40, 800_000, userKey: true),
        ]);

        $tester = new CommandTester(new AiCostsCommand($calls, self::brand()));
        $tester->execute(['--month' => '2026-11']);

        $out = $tester->getDisplay();
        self::assertMatchesRegularExpression('/NouriLoop total\s.*10\s.*\$0\.2000/', $out);
        self::assertStringContainsString("40 call(s) were paid for with users' own keys", $out);
    }

    public function testAMalformedMonthIsRefused(): void
    {
        $calls = $this->createMock(AiCallRepository::class);
        $calls->expects(self::never())->method('totals');

        $tester = new CommandTester(new AiCostsCommand($calls, self::brand()));
        self::assertSame(Command::INVALID, $tester->execute(['--month' => '2026-13']));
    }

    /** @return array{feature: string, provider: string, model: string, user_key: bool, calls: int, users: int,
     *     input_tokens: int,
     *     output_tokens: int, cost_micros: int, unpriced_calls: int} */
    private function row(
        string $feature,
        string $model,
        int $calls,
        int $costMicros,
        int $unpriced = 0,
        bool $userKey = false,
    ): array {
        return [
            'feature' => $feature, 'provider' => 'anthropic', 'model' => $model, 'user_key' => $userKey,
            'calls' => $calls, 'users' => 1,
            'input_tokens' => $calls * 2500, 'output_tokens' => $calls * 850,
            'cost_micros' => $costMicros, 'unpriced_calls' => $unpriced,
        ];
    }

    public function testASelfHostedCopyLabelsItsOwnSpendWithItsOwnName(): void
    {
        $calls = $this->createStub(AiCallRepository::class);
        $calls->method('totals')->willReturn([$this->row('meal_analysis', 'claude-sonnet-4-6', 2, 36_400)]);

        $tester = new CommandTester(new AiCostsCommand($calls, self::brand('self', 'https://food.example.org')));
        $tester->execute([]);

        $out = $tester->getDisplay();
        self::assertMatchesRegularExpression('/food\.example\.org total\s.*2\s.*\$0\.0364/', $out);
        self::assertStringNotContainsString('NouriLoop', $out);
    }

    private static function brand(
        string $mode = HostingMode::HOSTED,
        string $defaultUri = 'https://nouriloop.com',
    ): Brand {
        return new Brand(new HostingMode($mode), '', $defaultUri, '', '', '', '', '');
    }
}
