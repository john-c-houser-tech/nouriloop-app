<?php

declare(strict_types=1);

namespace App\Enum;

enum ScenarioType: string
{
    case FavoriteDaily    = 'favorite_daily';
    case SupplementAdd    = 'supplement_add';
    case SupplementRemove = 'supplement_remove';
    case FreeText         = 'free_text';

    public function label(): string
    {
        return match ($this) {
            self::FavoriteDaily    => 'Eat a favorite meal every day',
            self::SupplementAdd    => 'Add a supplement',
            self::SupplementRemove => 'Remove a supplement',
            self::FreeText         => 'Describe a scenario',
        };
    }

    public function requiresFavorite(): bool
    {
        return $this === self::FavoriteDaily;
    }

    public function requiresSupplement(): bool
    {
        return $this === self::SupplementAdd || $this === self::SupplementRemove;
    }

    public function requiresFreeText(): bool
    {
        return $this === self::FreeText;
    }
}
