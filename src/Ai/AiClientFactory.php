<?php

declare(strict_types=1);

namespace App\Ai;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds the instance's {@see AiClient} from configuration (`AI_PROVIDER` and
 * friends in `.env`). Anthropic is the default and keeps its own service, so the
 * test environment's faked HTTP client still applies to it. It arrives as a closure
 * so that an instance on another provider never builds it, and so never needs
 * `ANTHROPIC_API_KEY` to be set.
 */
final class AiClientFactory
{
    public const PROVIDER_ANTHROPIC = 'anthropic';
    public const PROVIDER_OPENAI_COMPATIBLE = 'openai_compatible';

    /** @param \Closure(): AnthropicClient $anthropic */
    public static function create(
        string $provider,
        \Closure $anthropic,
        HttpClientInterface $httpClient,
        string $baseUrl,
        string $apiKey,
        string $model,
        string $lightModel,
    ): AiClient {
        return match (trim($provider)) {
            self::PROVIDER_ANTHROPIC, '' => $anthropic(),
            self::PROVIDER_OPENAI_COMPATIBLE => new OpenAiCompatibleClient(
                $httpClient,
                $baseUrl,
                $apiKey,
                $model,
                $lightModel,
            ),
            default => throw new \InvalidArgumentException(sprintf(
                'Unknown AI_PROVIDER "%s"; use "%s" or "%s".',
                $provider,
                self::PROVIDER_ANTHROPIC,
                self::PROVIDER_OPENAI_COMPATIBLE,
            )),
        };
    }
}
