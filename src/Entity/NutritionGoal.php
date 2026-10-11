<?php

declare(strict_types=1);

namespace App\Entity;

use App\Dto\GoalDefinition;
use App\Enum\GoalFrequency;
use App\Enum\GoalOutputFormat;
use App\Repository\NutritionGoalRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user's nutrition goal "record": the structured definition the goal-setting
 * conversation produced, the (editable, versioned) evaluation prompt run against
 * the Claude API, the check-in schedule, and delivery preferences.
 *
 * The schedule is stored as structured fields (frequency/day/hour/minute, in the
 * goal owner's timezone — see GoalScheduler) rather than a raw cron/rrule string, and
 * the next due time is materialised in $nextRunAt (an absolute instant in the server
 * frame) so the cron-driven runner is restart-safe (due state lives in the DB).
 */
#[ORM\Entity(repositoryClass: NutritionGoalRepository::class)]
class NutritionGoal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 120)]
    private string $name = '';

    /**
     * The GoalDefinition::toArray() shape. Access through getDefinition().
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $definition = [];

    /** Editable prompt template, run against Claude each check-in (prompt-as-data). */
    #[ORM\Column(type: 'text')]
    private string $evaluationPromptTemplate = '';

    /** Bumped whenever the template is edited, so check-ins can record which version they used. */
    #[ORM\Column(options: ['default' => 1])]
    private int $promptVersion = 1;

    // ── Schedule ─────────────────────────────────────────────────────────
    #[ORM\Column(length: 10, enumType: GoalFrequency::class)]
    private GoalFrequency $frequency = GoalFrequency::Weekly;

    /** ISO-8601 day of week (1 = Monday … 7 = Sunday); only used when weekly. */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $dayOfWeek = 7;

    #[ORM\Column(type: 'smallint', options: ['default' => 18])]
    private int $hour = 18;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $minute = 0;

    // ── Delivery ─────────────────────────────────────────────────────────
    #[ORM\Column(length: 10, enumType: GoalOutputFormat::class, options: ['default' => 'summary'])]
    private GoalOutputFormat $outputFormat = GoalOutputFormat::Summary;

    // Delivery channel is not stored per-goal; it follows the user's notification
    // channel setting (User::getNotificationChannel()) so there is a single place
    // to configure delivery across both the digest and goal check-ins.

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** When the next scheduled check-in is due. Recomputed after each run. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $nextRunAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastRunAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDefinition(): GoalDefinition
    {
        return GoalDefinition::fromArray($this->definition);
    }

    public function setDefinition(GoalDefinition $definition): static
    {
        $this->definition = $definition->toArray();

        return $this;
    }

    public function getEvaluationPromptTemplate(): string
    {
        return $this->evaluationPromptTemplate;
    }

    public function setEvaluationPromptTemplate(string $template): static
    {
        $this->evaluationPromptTemplate = $template;

        return $this;
    }

    public function getPromptVersion(): int
    {
        return $this->promptVersion;
    }

    /** Replaces the template and bumps the version (no-op if unchanged). */
    public function revisePromptTemplate(string $template): static
    {
        if ($template !== $this->evaluationPromptTemplate) {
            $this->evaluationPromptTemplate = $template;
            ++$this->promptVersion;
        }

        return $this;
    }

    public function getFrequency(): GoalFrequency
    {
        return $this->frequency;
    }

    public function setFrequency(GoalFrequency $frequency): static
    {
        $this->frequency = $frequency;

        return $this;
    }

    public function getDayOfWeek(): ?int
    {
        return $this->dayOfWeek;
    }

    public function setDayOfWeek(?int $dayOfWeek): static
    {
        $this->dayOfWeek = $dayOfWeek === null ? null : max(1, min(7, $dayOfWeek));

        return $this;
    }

    public function getHour(): int
    {
        return $this->hour;
    }

    public function setHour(int $hour): static
    {
        $this->hour = max(0, min(23, $hour));

        return $this;
    }

    public function getMinute(): int
    {
        return $this->minute;
    }

    public function setMinute(int $minute): static
    {
        $this->minute = max(0, min(59, $minute));

        return $this;
    }

    public function getOutputFormat(): GoalOutputFormat
    {
        return $this->outputFormat;
    }

    public function setOutputFormat(GoalOutputFormat $outputFormat): static
    {
        $this->outputFormat = $outputFormat;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getNextRunAt(): ?\DateTimeImmutable
    {
        return $this->nextRunAt;
    }

    public function setNextRunAt(?\DateTimeImmutable $nextRunAt): static
    {
        $this->nextRunAt = $nextRunAt;

        return $this;
    }

    public function getLastRunAt(): ?\DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    public function setLastRunAt(?\DateTimeImmutable $lastRunAt): static
    {
        $this->lastRunAt = $lastRunAt;

        return $this;
    }
}
