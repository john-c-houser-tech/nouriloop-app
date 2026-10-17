<?php

declare(strict_types=1);

namespace App\Entity;

use App\Ai\AiFeature;
use App\Repository\AiCallRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One AI call that came back, whatever it was for: the per-call cost record (ADR 0003).
 * Written by {@see \App\Ai\MeteredAiClient} for every call through the AiClient seam,
 * with a plain DBAL insert so recording never flushes a caller's unit of work; this
 * mapping exists for the schema and for reading the rows back.
 *
 * It is measurement, not the allowance: what a Free user may still spend is counted
 * as before ({@see \App\Service\AnalysisAllowance}, ADR 0001), and nothing reads this
 * table to refuse a call.
 */
#[ORM\Entity(repositoryClass: AiCallRepository::class)]
#[ORM\Index(name: 'idx_ai_call_occurred', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_ai_call_user_occurred', columns: ['user_id', 'occurred_at'])]
class AiCall
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Null for an anonymous visitor (the help assistant). SET NULL on delete: when an
     * account is purged its calls stay as anonymous rows, so cost totals don't drop.
     * The row holds no content — feature, model, tokens and a time.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(length: 40, enumType: AiFeature::class)]
    private AiFeature $feature;

    /** `anthropic`, or an OpenAI-compatible endpoint's host (e.g. `openrouter.ai`). */
    #[ORM\Column(length: 100)]
    private string $provider = '';

    #[ORM\Column(length: 150)]
    private string $model = '';

    #[ORM\Column]
    private int $inputTokens = 0;

    #[ORM\Column]
    private int $outputTokens = 0;

    /** Estimated cost in micro-dollars from {@see \App\Ai\ModelPrices}; null when the model has no listed price. */
    #[ORM\Column(nullable: true)]
    private ?int $costMicros = null;

    /**
     * True when the user's own key paid (Settings ▸ AI provider): the estimate is what it
     * cost them, not NouriLoop, and the report keeps it out of NouriLoop's spend.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $userKey = false;

    /** Server frame, not the user's wall-clock: these rows are compared across users. */
    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    public function __construct(AiFeature $feature)
    {
        $this->feature = $feature;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getFeature(): AiFeature
    {
        return $this->feature;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getInputTokens(): int
    {
        return $this->inputTokens;
    }

    public function getOutputTokens(): int
    {
        return $this->outputTokens;
    }

    public function getCostMicros(): ?int
    {
        return $this->costMicros;
    }

    public function isUserKey(): bool
    {
        return $this->userKey;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
