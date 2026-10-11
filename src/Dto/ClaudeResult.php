<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * The result of a single Claude call: the assistant's reply plus token usage
 * (so callers can log API cost per scheduled run / conversation turn / analysis).
 */
final readonly class ClaudeResult
{
    public function __construct(
        public string $text,
        public int $inputTokens,
        public int $outputTokens,
    ) {
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    /**
     * The reply as a JSON object, tolerating Markdown code fences and any
     * surrounding prose/whitespace by falling back to the outermost { … }.
     *
     * @return array<mixed>
     *
     * @throws \RuntimeException when no JSON object can be recovered
     */
    public function json(): array
    {
        $data = json_decode(trim($this->text), true);
        if (!is_array($data)) {
            $start = strpos($this->text, '{');
            $end = strrpos($this->text, '}');
            $data = $start === false || $end === false || $end <= $start
                ? null
                : json_decode(substr($this->text, $start, $end - $start + 1), true);
        }
        if (!is_array($data)) {
            throw new \RuntimeException('Claude returned invalid JSON: ' . $this->text);
        }

        return $data;
    }
}
