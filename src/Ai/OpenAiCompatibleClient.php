<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\User;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The {@see AiClient} for any OpenAI-compatible chat-completions endpoint: OpenRouter
 * (which fronts OpenAI, Anthropic and many others), OpenAI itself, or a self-hosted
 * server such as Ollama's `/v1`. Raw HTTP via Symfony HttpClient, like
 * {@see AnthropicClient}; the two differ only in wire format.
 *
 *  - The system prompt becomes the first message (`role: system`).
 *  - Images go in one user message as `image_url` parts carrying base64 data URIs,
 *    followed by the prompt as a `text` part. Whether the model can read them is the
 *    operator's choice of model (a vision benchmark gate comes later, ADR 0003).
 *  - The API key is optional: a local Ollama server needs none, so no
 *    `Authorization` header is sent when it is blank.
 */
class OpenAiCompatibleClient implements AiClient
{
    /** Whole-call cap (seconds), the same as {@see AnthropicClient}'s. */
    private const HTTP_MAX_DURATION = 120;

    private readonly string $endpoint;
    private readonly string $lightModel;
    private readonly string $visionModel;

    /** What the cost record calls this provider: the endpoint's host, e.g. openrouter.ai. */
    private readonly string $provider;

    /**
     * @param string $baseUrl     API root, e.g. https://openrouter.ai/api/v1 or http://localhost:11434/v1
     * @param string $apiKey      bearer key; '' sends no Authorization header
     * @param string $model       main model, required (there is no sensible default across providers)
     * @param string $lightModel  model for light features; '' uses the main model
     * @param string $visionModel model for photo features; '' uses the main model
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        string $baseUrl,
        private readonly string $apiKey,
        private readonly string $model,
        string $lightModel = '',
        string $visionModel = '',
    ) {
        if (trim($baseUrl) === '' || trim($model) === '') {
            throw new \InvalidArgumentException('An OpenAI-compatible client needs a base URL and a model.');
        }
        $this->endpoint = rtrim($baseUrl, '/') . '/chat/completions';
        $host = (string) parse_url($baseUrl, PHP_URL_HOST);
        $port = parse_url($baseUrl, PHP_URL_PORT);
        $this->provider = $host === '' ? 'openai_compatible' : $host . ($port !== null ? ':' . $port : '');
        $this->lightModel = $lightModel !== '' ? $lightModel : $model;
        $this->visionModel = $visionModel !== '' ? $visionModel : $model;
    }

    public function complete(
        AiFeature $feature,
        ?User $user,
        string $system,
        array $messages,
        int $maxTokens = 1500,
    ): AiResult {
        $turns = $system === '' ? [] : [['role' => 'system', 'content' => $system]];
        foreach ($messages as $m) {
            $turns[] = ['role' => $m['role'], 'content' => $m['content']];
        }

        return $this->send($feature, $turns, $maxTokens);
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
                'type' => 'image_url',
                'image_url' => ['url' => 'data:' . $image['mime'] . ';base64,' . base64_encode($image['bytes'])],
            ];
        }
        $content[] = ['type' => 'text', 'text' => $prompt];

        return $this->send($feature, [['role' => 'user', 'content' => $content]], $maxTokens);
    }

    /** @param list<array{role: string, content: mixed}> $messages */
    private function send(AiFeature $feature, array $messages, int $maxTokens): AiResult
    {
        $headers = ['content-type' => 'application/json'];
        if ($this->apiKey !== '') {
            $headers['authorization'] = 'Bearer ' . $this->apiKey;
        }

        $model = match (true) {
            $feature->isLight() => $this->lightModel,
            $feature->needsVision() => $this->visionModel,
            default => $this->model,
        };
        $response = $this->httpClient->request('POST', $this->endpoint, [
            'max_duration' => self::HTTP_MAX_DURATION,
            'headers' => $headers,
            'json' => [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'messages' => $messages,
            ],
        ]);

        return $this->result($response->toArray(), $model);
    }

    /** @param array<string, mixed> $body */
    private function result(array $body, string $model): AiResult
    {
        // `content` is normally a string; some servers return a list of parts instead.
        $content = $body['choices'][0]['message']['content'] ?? '';
        if (is_array($content)) {
            $content = implode('', array_map(
                static fn(mixed $part): string => is_array($part) ? (string) ($part['text'] ?? '') : '',
                $content,
            ));
        }

        return new AiResult(
            (string) $content,
            (int) ($body['usage']['prompt_tokens'] ?? 0),
            (int) ($body['usage']['completion_tokens'] ?? 0),
            $this->provider,
            $model,
        );
    }
}
