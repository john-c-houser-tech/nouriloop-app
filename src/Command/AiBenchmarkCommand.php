<?php

declare(strict_types=1);

namespace App\Command;

use App\Ai\VisionBenchmark;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs the vision benchmark ({@see VisionBenchmark}) against the instance's own photo
 * model — the AI_PROVIDER / AI_MODEL configuration, not any user's key. For operators:
 * check that NouriLoop's model passes its own gate before telling users about it, and a
 * self-hoster can check the model they configured. Spends five calls on the instance key.
 */
#[AsCommand(
    name: 'app:ai-benchmark',
    description: "Check the instance's photo model against the five reference photos (5 AI calls)",
)]
class AiBenchmarkCommand extends Command
{
    public function __construct(
        private readonly VisionBenchmark $benchmark,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Vision benchmark — instance photo model');

        $rows = [];
        $passed = 0;
        foreach (VisionBenchmark::CASES as $i => $case) {
            $result = $this->benchmark->runCase(null, $i);
            $passed += $result->passed ? 1 : 0;
            $detail = $result->error
                ? 'no usable answer'
                : implode('; ', array_map(
                    static fn(array $m): string => sprintf(
                        '%s: expected %s, got %s',
                        $m['nutrient'],
                        $m['expected'],
                        $m['got'] ?? 'none',
                    ),
                    $result->misses,
                ));
            $rows[] = [$case['name'], $case['kind'], $result->passed ? 'pass' : 'FAIL', $detail];
        }
        $io->table(['Case', 'Kind', 'Result', 'Misses'], $rows);

        if ($passed === VisionBenchmark::caseCount()) {
            $io->success('All cases passed.');

            return Command::SUCCESS;
        }
        $total = VisionBenchmark::caseCount();
        $io->error(sprintf('%d of %d cases failed.', $total - $passed, $total));

        return Command::FAILURE;
    }
}
