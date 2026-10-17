<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\User;
use App\Repository\UserAiSettingsRepository;

/**
 * Decorates the instance's {@see AiClient} with per-user resolution (ADR 0003): a call
 * made for a user who has set their own provider and key goes to that provider, with
 * their key, and comes back marked {@see AiResult::$userKey}; every other call (no
 * user, or no settings) goes to the instance's client unchanged.
 *
 * Order in the decorator chain: this sits inside {@see MeteredAiClient}, so the cost
 * record sees the user's provider, model and the user-key mark.
 *
 * ⚠️ A user's failing key fails the call; it never falls back to NouriLoop's key, which
 * would bill us for a user who chose to pay their own way.
 */
final class ResolvingAiClient implements AiClient
{
    public function __construct(
        private readonly AiClient $instance,
        private readonly UserAiSettingsRepository $settings,
        private readonly UserAiClientBuilder $builder,
    ) {
    }

    public function complete(
        AiFeature $feature,
        ?User $user,
        string $system,
        array $messages,
        int $maxTokens = 1500,
    ): AiResult {
        $own = $this->ownClient($user);
        if ($own === null) {
            return $this->instance->complete($feature, $user, $system, $messages, $maxTokens);
        }

        return $own->complete($feature, $user, $system, $messages, $maxTokens)->withUserKey();
    }

    public function completeWithImages(
        AiFeature $feature,
        ?User $user,
        string $prompt,
        array $imagePaths,
        int $maxTokens,
    ): AiResult {
        $own = $this->ownClient($user);
        if ($own === null) {
            return $this->instance->completeWithImages($feature, $user, $prompt, $imagePaths, $maxTokens);
        }

        return $own->completeWithImages($feature, $user, $prompt, $imagePaths, $maxTokens)->withUserKey();
    }

    private function ownClient(?User $user): ?AiClient
    {
        if ($user === null || $user->getId() === null) {
            return null;
        }
        $settings = $this->settings->findForUser($user);

        return $settings === null ? null : $this->builder->build($settings);
    }
}
