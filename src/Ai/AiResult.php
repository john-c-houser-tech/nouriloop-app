<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * The result of a single AI call, whatever the provider: the reply plus token
 * usage (so callers can log API cost per scheduled run / conversation turn / analysis),
 * which provider and model produced it, and whether the user's own key paid for it
 * (all three for the per-call cost record).
 */
final readonly class AiResult
{
    public function __construct(
        public string $text,
        public int $inputTokens,
        public int $outputTokens,
        public string $provider = '',
        public string $model = '',
        public bool $userKey = false,
    ) {
    }

    /** The same result, marked as paid for with the user's own key rather than NouriLoop's. */
    public function withUserKey(): self
    {
        return new self($this->text, $this->inputTokens, $this->outputTokens, $this->provider, $this->model, true);
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
            throw new \RuntimeException('The model returned invalid JSON: ' . $this->text);
        }

        return $data;
    }
}
