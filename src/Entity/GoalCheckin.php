<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\GoalCheckinStatus;
use App\Repository\GoalCheckinRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The record of one scheduled evaluation of a goal: the window looked at, the
 * deterministically-decided status, the Claude narrative, an optional chart, and
 * the token cost (so API spend per scheduled run stays visible).
 *
 * Deleted with its goal (ON DELETE CASCADE) — a check-in has no meaning without it.
 */
#[ORM\Entity(repositoryClass: GoalCheckinRepository::class)]
class GoalCheckin
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?NutritionGoal $goal = null;

    #[ORM\Column]
    private \DateTimeImmutable $runAt;

    #[ORM\Column]
    private \DateTimeImmutable $windowStart;

    #[ORM\Column]
    private \DateTimeImmutable $windowEnd;

    #[ORM\Column(length: 10, enumType: GoalCheckinStatus::class)]
    private GoalCheckinStatus $status = GoalCheckinStatus::NoData;

    /** The aggregated metric value over the window (null when no data). */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $currentValue = null;

    /** Snapshot of the target at evaluation time (goals can be edited later). */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $targetValue = null;

    /**
     * The trend key the evaluator computed ('first'|'improving'|'holding'|'slipping'),
     * so displays can explain WHY an under-target check-in is "On track" (improving vs.
     * first check-in). Null for no-data runs and check-ins predating the column.
     */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $trend = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $summaryText = null;

    /** Web path to the generated chart image, if any. */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $chartPath = null;

    /** Which prompt-template version produced this check-in. */
    #[ORM\Column(options: ['default' => 1])]
    private int $promptVersion = 1;

    #[ORM\Column(options: ['default' => 0])]
    private int $inputTokens = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $outputTokens = 0;

    public function __construct()
    {
        $this->runAt = new \DateTimeImmutable();
        $this->windowStart = $this->runAt;
        $this->windowEnd = $this->runAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGoal(): ?NutritionGoal
    {
        return $this->goal;
    }

    public function setGoal(?NutritionGoal $goal): static
    {
        $this->goal = $goal;

        return $this;
    }

    public function getRunAt(): \DateTimeImmutable
    {
        return $this->runAt;
    }

    public function setRunAt(\DateTimeImmutable $runAt): static
    {
        $this->runAt = $runAt;

        return $this;
    }

    public function getWindowStart(): \DateTimeImmutable
    {
        return $this->windowStart;
    }

    public function setWindowStart(\DateTimeImmutable $windowStart): static
    {
        $this->windowStart = $windowStart;

        return $this;
    }

    public function getWindowEnd(): \DateTimeImmutable
    {
        return $this->windowEnd;
    }

    public function setWindowEnd(\DateTimeImmutable $windowEnd): static
    {
        $this->windowEnd = $windowEnd;

        return $this;
    }

    public function getStatus(): GoalCheckinStatus
    {
        return $this->status;
    }

    public function setStatus(GoalCheckinStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCurrentValue(): ?float
    {
        return $this->currentValue;
    }

    public function setCurrentValue(?float $currentValue): static
    {
        $this->currentValue = $currentValue;

        return $this;
    }

    public function getTargetValue(): ?float
    {
        return $this->targetValue;
    }

    public function setTargetValue(?float $targetValue): static
    {
        $this->targetValue = $targetValue;

        return $this;
    }

    public function getTrend(): ?string
    {
        return $this->trend;
    }

    public function setTrend(?string $trend): static
    {
        $this->trend = $trend;

        return $this;
    }

    public function getSummaryText(): ?string
    {
        return $this->summaryText;
    }

    public function setSummaryText(?string $summaryText): static
    {
        $this->summaryText = $summaryText;

        return $this;
    }

    public function getChartPath(): ?string
    {
        return $this->chartPath;
    }

    public function setChartPath(?string $chartPath): static
    {
        $this->chartPath = $chartPath;

        return $this;
    }

    public function getPromptVersion(): int
    {
        return $this->promptVersion;
    }

    public function setPromptVersion(int $promptVersion): static
    {
        $this->promptVersion = $promptVersion;

        return $this;
    }

    public function getInputTokens(): int
    {
        return $this->inputTokens;
    }

    public function getOutputTokens(): int
    {
        return $this->outputTokens;
    }

    public function setTokens(int $inputTokens, int $outputTokens): static
    {
        $this->inputTokens = $inputTokens;
        $this->outputTokens = $outputTokens;

        return $this;
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }
}
