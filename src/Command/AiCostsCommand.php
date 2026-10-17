<?php

declare(strict_types=1);

namespace App\Command;

use App\Hosting\Brand;
use App\Repository\AiCallRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What the AI calls in a window cost, from the per-call record (`ai_call`, ADR 0003):
 * one row per feature, provider and model, plus totals. This is how the at-cost Full
 * price is checked against what calls actually cost (App Development Plan Q2b). The
 * instance's own spend is labelled with its brand name, so a self-hosted copy reads as its own.
 */
#[AsCommand(
    name: 'app:ai-costs',
    description: 'Report AI calls, tokens and estimated cost per feature and model for a month',
)]
class AiCostsCommand extends Command
{
    public function __construct(
        private readonly AiCallRepository $calls,
        private readonly Brand $brand,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'month',
            null,
            InputOption::VALUE_REQUIRED,
            'Calendar month to report, YYYY-MM (server frame; default: the current month)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $month = (string) ($input->getOption('month') ?? (new \DateTimeImmutable())->format('Y-m'));
        $from = \DateTimeImmutable::createFromFormat('!Y-m', $month);
        if ($from === false || $from->format('Y-m') !== $month) {
            $io->error(sprintf('"%s" is not a month; use YYYY-MM.', $month));

            return Command::INVALID;
        }
        $to = $from->modify('first day of next month');

        $rows = $this->calls->totals($from, $to);
        $io->title(sprintf('AI calls, %s', $from->format('F Y')));
        if ($rows === []) {
            $io->text('No AI calls recorded in this month.');

            return Command::SUCCESS;
        }

        // The instance's own spend is what the at-cost Full price is checked against; calls a
        // user paid for with their own key are listed but kept out of that total.
        $sum = ['calls' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost_micros' => 0, 'unpriced_calls' => 0];
        $userKeyCalls = 0;
        $table = [];
        foreach ($rows as $r) {
            if ($r['user_key']) {
                $userKeyCalls += $r['calls'];
            } else {
                foreach (array_keys($sum) as $k) {
                    $sum[$k] += $r[$k];
                }
            }
            $table[] = [
                $r['feature'],
                $r['provider'],
                $r['model'],
                $r['user_key'] ? 'user' : $this->brand->name(),
                $r['calls'],
                $r['users'],
                number_format($r['input_tokens']),
                number_format($r['output_tokens']),
                $r['unpriced_calls'] === $r['calls'] ? 'n/a' : self::dollars($r['cost_micros']),
            ];
        }
        $table[] = [
            $this->brand->name() . ' total', '', '', '', $sum['calls'], '',
            number_format($sum['input_tokens']), number_format($sum['output_tokens']),
            self::dollars($sum['cost_micros']),
        ];
        $io->table(
            ['Feature', 'Provider', 'Model', 'Paid by', 'Calls', 'Users', 'Input tokens', 'Output tokens', 'Est. cost'],
            $table,
        );

        if ($userKeyCalls > 0) {
            $io->note(sprintf(
                '%d call(s) were paid for with users\' own keys and are not in the total.',
                $userKeyCalls,
            ));
        }
        if ($sum['unpriced_calls'] > 0) {
            $io->note(sprintf(
                '%d call(s) used a model with no listed price (App\Ai\ModelPrices), so the total leaves them out.',
                $sum['unpriced_calls'],
            ));
        }

        return Command::SUCCESS;
    }

    private static function dollars(int $micros): string
    {
        return '$' . number_format($micros / 1_000_000, 4);
    }
}
