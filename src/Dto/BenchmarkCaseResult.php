<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * How one vision-benchmark case went ({@see \App\Ai\VisionBenchmark}): passed, the
 * nutrients that missed (expected vs what the model said; null = it gave no number),
 * or `error` when the model couldn't answer at all.
 */
final readonly class BenchmarkCaseResult
{
    /**
     * @param list<array{nutrient: string, expected: float, got: float|null}> $misses
     */
    public function __construct(
        public string $name,
        public bool $passed,
        public array $misses,
        public bool $error = false,
    ) {
    }

    /** @return array{name: string, passed: bool, misses: list<array{nutrient: string, expected: float, got: float|null}>, error: bool} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'passed' => $this->passed, 'misses' => $this->misses, 'error' => $this->error];
    }
}
