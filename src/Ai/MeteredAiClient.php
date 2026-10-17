<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Decorates the configured {@see AiClient} so every call that comes back writes one
 * `ai_call` row: feature, user, provider, model, tokens and an estimated cost
 * ({@see ModelPrices}). Sitting on the seam means no caller can forget to record.
 *
 * - Only calls that return are recorded; a call that throws produced no usage to bill.
 * - The insert is plain DBAL, never the ORM, so recording can't flush a caller's
 *   half-finished unit of work (a goal check-in mid-evaluation, a meal being saved).
 * - Recording failures are logged and swallowed: losing a cost row must never turn a
 *   paid-for answer into an error for the user.
 */
final class MeteredAiClient implements AiClient
{
    public function __construct(
        private readonly AiClient $inner,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function complete(
        AiFeature $feature,
        ?User $user,
        string $system,
        array $messages,
        int $maxTokens = 1500,
    ): AiResult {
        return $this->record($feature, $user, $this->inner->complete($feature, $user, $system, $messages, $maxTokens));
    }

    public function completeWithImages(
        AiFeature $feature,
        ?User $user,
        string $prompt,
        array $imagePaths,
        int $maxTokens,
    ): AiResult {
        return $this->record(
            $feature,
            $user,
            $this->inner->completeWithImages($feature, $user, $prompt, $imagePaths, $maxTokens),
        );
    }

    private function record(AiFeature $feature, ?User $user, AiResult $result): AiResult
    {
        try {
            $this->connection->insert('ai_call', [
                'user_id' => $user?->getId(),
                'feature' => $feature->value,
                'provider' => mb_substr($result->provider, 0, 100),
                'model' => mb_substr($result->model, 0, 150),
                'input_tokens' => $result->inputTokens,
                'output_tokens' => $result->outputTokens,
                'cost_micros' => ModelPrices::costMicros(
                    $result->provider,
                    $result->model,
                    $result->inputTokens,
                    $result->outputTokens,
                ),
                'user_key' => $result->userKey ? 1 : 0,
                'occurred_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not record AI call cost', [
                'feature' => $feature->value,
                'model' => $result->model,
                'exception' => $e,
            ]);
        }

        return $result;
    }
}
