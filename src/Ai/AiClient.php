<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\User;

/**
 * The one seam every AI call goes through (ADR 0003). Callers depend on this
 * interface, never on a provider class, and name the {@see AiFeature} rather than a
 * model, so which provider, key and model serve a call can change without touching
 * them. Two shapes of call:
 *
 *  - {@see complete()}: a text conversation (system prompt + alternating turns),
 *    used by goal setup/evaluation, the help assistant and the insight narratives;
 *  - {@see completeWithImages()}: one user turn of photos + a prompt and no system
 *    prompt, used by the meal, recipe and supplement analyses.
 */
interface AiClient
{
    /**
     * @param AiFeature $feature what the call is for; the client picks the model from it
     * @param User|null $user whose call it is (null for an anonymous visitor); every call is recorded against it
     * @param list<array{role: string, content: string}> $messages alternating user/assistant turns, starting with user
     */
    public function complete(
        AiFeature $feature,
        ?User $user,
        string $system,
        array $messages,
        int $maxTokens = 1500,
    ): AiResult;

    /**
     * One user turn: the images (in order) followed by the prompt, with no system
     * prompt. Blank, missing and unreadable paths are dropped; if paths were given and none
     * remain, it throws before any request. An empty $imagePaths is a text-only
     * call in the same block shape.
     *
     * @param AiFeature $feature what the call is for; the client picks the model from it
     * @param User|null $user whose call it is; every call is recorded against it
     * @param list<string> $imagePaths
     *
     * @throws \RuntimeException when $imagePaths is non-empty but none can be read
     */
    public function completeWithImages(
        AiFeature $feature,
        ?User $user,
        string $prompt,
        array $imagePaths,
        int $maxTokens,
    ): AiResult;
}
