<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\HealthMetricType;
use App\Repository\HealthMetricRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HealthMetricRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_health_metric', columns: ['user_id', 'metric_type', 'recorded_at'])]
class HealthMetric
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'healthMetrics')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(enumType: HealthMetricType::class)]
    private ?HealthMetricType $metricType = null;

    #[ORM\Column(type: 'float')]
    private ?float $value = null;

    #[ORM\Column]
    private \DateTimeImmutable $recordedAt;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $source = null;

    public function __construct()
    {
        $this->recordedAt = new \DateTimeImmutable();
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

    public function getMetricType(): ?HealthMetricType
    {
        return $this->metricType;
    }

    public function setMetricType(HealthMetricType $metricType): static
    {
        $this->metricType = $metricType;

        return $this;
    }

    public function getValue(): ?float
    {
        return $this->value;
    }

    public function setValue(float $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function setRecordedAt(\DateTimeImmutable $recordedAt): static
    {
        $this->recordedAt = $recordedAt;

        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(?string $source): static
    {
        $this->source = $source;

        return $this;
    }
}
