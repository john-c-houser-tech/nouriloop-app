<?php

declare(strict_types=1);

namespace App\Entity;

use App\Ai\UserAiProvider;
use App\Repository\UserAiSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user's own AI provider and key (Settings ▸ AI provider, ADR 0003). When present,
 * every AI call made for this user goes to their provider with their key instead of
 * NouriLoop's ({@see \App\Ai\ResolvingAiClient}). One row per user at most.
 *
 * The key is stored encrypted ({@see \App\Security\UserSecretBox}); only its last four
 * characters are kept in clear, to show which key is on file. Neither is ever logged.
 */
#[ORM\Entity(repositoryClass: UserAiSettingsRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_user_ai_settings_user', columns: ['user_id'])]
class UserAiSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20, enumType: UserAiProvider::class)]
    private UserAiProvider $provider;

    /** Custom endpoints only (https, public address); null for Anthropic and OpenRouter. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $baseUrl = null;

    /** The API key, encrypted. */
    #[ORM\Column(type: 'text')]
    private string $encryptedKey = '';

    /** Last four characters of the key, shown as "•••• abcd". */
    #[ORM\Column(length: 4)]
    private string $keyHint = '';

    /** Null = "Let NouriLoop choose" ({@see UserAiProvider::defaultModel()}). */
    #[ORM\Column(length: 150, nullable: true)]
    private ?string $textModel = null;

    /** Model for photo features; null = the text model (or the provider default). */
    #[ORM\Column(length: 150, nullable: true)]
    private ?string $visionModel = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * The photo model the stored benchmark results are for ({@see effectiveVisionModel()}
     * at the time). Results for any other model are stale, so a change resets them.
     */
    #[ORM\Column(length: 150, nullable: true)]
    private ?string $benchmarkModel = null;

    /**
     * Per-case results keyed by case name ({@see \App\Dto\BenchmarkCaseResult::toArray()}).
     *
     * @var array<string, array<string, mixed>>
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $benchmarkResults = null;

    /** When the last benchmark case was recorded. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $benchmarkRanAt = null;

    public function __construct(User $user, UserAiProvider $provider)
    {
        $this->user = $user;
        $this->provider = $provider;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getProvider(): UserAiProvider
    {
        return $this->provider;
    }

    public function setProvider(UserAiProvider $provider): static
    {
        $this->provider = $provider;

        return $this->touch();
    }

    public function getBaseUrl(): ?string
    {
        return $this->baseUrl;
    }

    public function setBaseUrl(?string $baseUrl): static
    {
        $this->baseUrl = $baseUrl;

        return $this->touch();
    }

    public function getEncryptedKey(): string
    {
        return $this->encryptedKey;
    }

    public function setEncryptedKey(string $encryptedKey, string $keyHint): static
    {
        $this->encryptedKey = $encryptedKey;
        $this->keyHint = $keyHint;

        return $this->touch();
    }

    public function getKeyHint(): string
    {
        return $this->keyHint;
    }

    public function getTextModel(): ?string
    {
        return $this->textModel;
    }

    public function setTextModel(?string $textModel): static
    {
        $this->textModel = $textModel;

        return $this->touch();
    }

    public function getVisionModel(): ?string
    {
        return $this->visionModel;
    }

    public function setVisionModel(?string $visionModel): static
    {
        $this->visionModel = $visionModel;

        return $this->touch();
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * The photo model a call would actually use: the photo slot, else the text slot, else
     * the provider's default ('' for a custom service with neither, which can't happen
     * once saved: its text model is required).
     */
    public function effectiveVisionModel(): string
    {
        return $this->visionModel ?? $this->textModel ?? (string) $this->provider->defaultModel();
    }

    /** Clears stored results and records which model the next run is for. */
    public function resetBenchmark(): static
    {
        $this->benchmarkModel = $this->effectiveVisionModel();
        $this->benchmarkResults = [];
        $this->benchmarkRanAt = null;

        return $this;
    }

    /** @param array<string, mixed> $result one case, as {@see \App\Dto\BenchmarkCaseResult::toArray()} */
    public function recordBenchmarkCase(array $result): static
    {
        if ($this->benchmarkModel !== $this->effectiveVisionModel()) {
            $this->resetBenchmark();
        }
        $results = $this->benchmarkResults ?? [];
        $results[(string) $result['name']] = $result;
        $this->benchmarkResults = $results;
        $this->benchmarkRanAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * Results for the current photo model, in case order; empty when none are stored or
     * they were for another model.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getBenchmarkResults(): array
    {
        return $this->benchmarkModel === $this->effectiveVisionModel() ? ($this->benchmarkResults ?? []) : [];
    }

    /**
     * True or false once every case has a result for the current photo model; null while
     * it hasn't been checked (or only partly).
     */
    public function benchmarkPassed(int $caseCount): ?bool
    {
        $results = $this->getBenchmarkResults();
        if (\count($results) < $caseCount) {
            return null;
        }

        return array_reduce($results, static fn(bool $all, array $r): bool => $all && (bool) $r['passed'], true);
    }

    public function getBenchmarkRanAt(): ?\DateTimeImmutable
    {
        return $this->benchmarkRanAt;
    }

    private function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
