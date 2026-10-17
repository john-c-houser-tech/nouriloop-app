<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\User;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The {@see AiClient} for Anthropic's Messages API: endpoint, headers, model,
 * time cap, image encoding and token usage. Raw HTTP via Symfony HttpClient
 * (no SDK dependency). Light features ({@see AiFeature::isLight()}) get the light
 * model, photo features ({@see AiFeature::needsVision()}) the vision model, and the
 * rest the main model; they default to the models the app has always used and can be
 * overridden by the instance config or a user's own settings.
 */
class AnthropicClient implements AiClient
{
    public const PROVIDER = 'anthropic';
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    public const MODEL = 'claude-sonnet-4-6';
    public const LIGHT_MODEL = 'claude-haiku-4-5-20251001';
    private const API_VERSION = '2023-06-01';

    /** Whole-call cap (seconds) so a stuck upstream can't pin a php-fpm worker. */
    private const HTTP_MAX_DURATION = 120;

    private readonly string $model;
    private readonly string $lightModel;
    private readonly string $visionModel;

    /**
     * @param string $model       main model; '' keeps {@see MODEL}
     * @param string $lightModel  model for light features; '' keeps {@see LIGHT_MODEL}
     * @param string $visionModel model for photo features; '' uses the main model
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $anthropicApiKey,
        string $model = '',
        string $lightModel = '',
        string $visionModel = '',
    ) {
        $this->model = $model !== '' ? $model : self::MODEL;
        $this->lightModel = $lightModel !== '' ? $lightModel : self::LIGHT_MODEL;
        $this->visionModel = $visionModel !== '' ? $visionModel : $this->model;
    }

    public function complete(
        AiFeature $feature,
        ?User $user,
        string $system,
        array $messages,
        int $maxTokens = 1500,
    ): AiResult {
        $response = $this->httpClient->request('POST', self::API_URL, [
            'max_duration' => self::HTTP_MAX_DURATION,
            'headers' => $this->headers(),
            'json' => [
                'model' => $this->modelFor($feature),
                'max_tokens' => $maxTokens,
                'system' => $system,
                'messages' => array_map(
                    static fn (array $m): array => [
                        'role' => $m['role'],
                        'content' => $m['content'],
                    ],
                    $messages,
                ),
            ],
        ]);

        return $this->result($response->toArray(), $this->modelFor($feature));
    }

    public function completeWithImages(
        AiFeature $feature,
        ?User $user,
        string $prompt,
        array $imagePaths,
        int $maxTokens,
    ): AiResult {
        $content = [];
        foreach (ImageInputs::load($imagePaths) as $image) {
            $content[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $image['mime'],
                    'data' => base64_encode($image['bytes']),
                ],
            ];
        }
        $content[] = ['type' => 'text', 'text' => $prompt];

        $response = $this->httpClient->request('POST', self::API_URL, [
            'max_duration' => self::HTTP_MAX_DURATION,
            'headers' => $this->headers(),
            'json' => [
                'model' => $this->modelFor($feature),
                'max_tokens' => $maxTokens,
                'messages' => [['role' => 'user', 'content' => $content]],
            ],
        ]);

        return $this->result($response->toArray(), $this->modelFor($feature));
    }

    private function modelFor(AiFeature $feature): string
    {
        return match (true) {
            $feature->isLight() => $this->lightModel,
            $feature->needsVision() => $this->visionModel,
            default => $this->model,
        };
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'x-api-key' => $this->anthropicApiKey,
            'anthropic-version' => self::API_VERSION,
            'content-type' => 'application/json',
        ];
    }

    /** @param array<string, mixed> $body */
    private function result(array $body, string $model): AiResult
    {
        return new AiResult(
            (string) ($body['content'][0]['text'] ?? ''),
            (int) ($body['usage']['input_tokens'] ?? 0),
            (int) ($body['usage']['output_tokens'] ?? 0),
            self::PROVIDER,
            $model,
        );
    }
}
