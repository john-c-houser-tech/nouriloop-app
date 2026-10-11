<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ClaudeResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The one module that speaks Claude's Messages API: endpoint, headers, model,
 * time cap, image encoding and token usage. Two shapes of call:
 *
 *  - {@see complete()}: a text conversation (system prompt + alternating turns),
 *    used by goal setup/evaluation, the help assistant and the insight narratives;
 *  - {@see completeWithImages()}: one user turn of photos + a prompt and no system
 *    prompt, used by the meal, recipe and supplement analyses.
 *
 * Raw HTTP via Symfony HttpClient (no SDK dependency).
 */
class ClaudeClient
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    public const MODEL = 'claude-sonnet-4-6';
    private const API_VERSION = '2023-06-01';

    /** Whole-call cap (seconds) so a stuck upstream can't pin a php-fpm worker. */
    private const HTTP_MAX_DURATION = 120;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $anthropicApiKey,
    ) {
    }

    /**
     * @param list<array{role: string, content: string}> $messages alternating user/assistant turns, starting with user
     * @param string|null $model override the default model (e.g. a cheaper haiku for the help assistant)
     */
    public function complete(
        string $system,
        array $messages,
        int $maxTokens = 1500,
        ?string $model = null,
    ): ClaudeResult {
        $response = $this->httpClient->request('POST', self::API_URL, [
            'max_duration' => self::HTTP_MAX_DURATION,
            'headers' => $this->headers(),
            'json' => [
                'model' => $model ?? self::MODEL,
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

        return $this->result($response->toArray());
    }

    /**
     * One user turn: the images (in order) followed by the prompt, with no system
     * prompt. Missing or blank paths are dropped; if paths were given and none
     * remain, it throws before any request. An empty $imagePaths is a text-only
     * call in the same block shape.
     *
     * @param list<string> $imagePaths
     *
     * @throws \RuntimeException when $imagePaths is non-empty but none can be read
     */
    public function completeWithImages(string $prompt, array $imagePaths, int $maxTokens): ClaudeResult
    {
        $paths = array_values(array_filter(
            $imagePaths,
            static fn(string $p): bool => $p !== '' && is_file($p),
        ));
        if ($imagePaths !== [] && $paths === []) {
            throw new \RuntimeException('No readable image to analyze.');
        }

        $content = [];
        foreach ($paths as $path) {
            [$bytes, $mime] = ApiImageEncoder::encode($path);
            $content[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $mime,
                    'data' => base64_encode($bytes),
                ],
            ];
        }
        $content[] = ['type' => 'text', 'text' => $prompt];

        $response = $this->httpClient->request('POST', self::API_URL, [
            'max_duration' => self::HTTP_MAX_DURATION,
            'headers' => $this->headers(),
            'json' => [
                'model' => self::MODEL,
                'max_tokens' => $maxTokens,
                'messages' => [['role' => 'user', 'content' => $content]],
            ],
        ]);

        return $this->result($response->toArray());
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
    private function result(array $body): ClaudeResult
    {
        return new ClaudeResult(
            (string) ($body['content'][0]['text'] ?? ''),
            (int) ($body['usage']['input_tokens'] ?? 0),
            (int) ($body['usage']['output_tokens'] ?? 0),
        );
    }
}
