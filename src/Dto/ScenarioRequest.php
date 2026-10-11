<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\ScenarioType;

final class ScenarioRequest
{
    /**
     * @param list<string> $dimensions e.g. ['inflammation', 'nutrition', 'weight', 'symptoms']
     */
    public function __construct(
        public readonly ScenarioType $type,
        public readonly int $durationWeeks,
        public readonly array $dimensions,
        public readonly ?int $favoriteId = null,
        public readonly ?int $supplementId = null,
        public readonly ?string $freeText = null,
    ) {
    }
}
