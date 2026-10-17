<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\AiClientFactory;
use App\Ai\AnthropicClient;
use App\Ai\OpenAiCompatibleClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class AiClientFactoryTest extends TestCase
{
    public function testAnthropicIsTheDefaultAndBlankMeansAnthropic(): void
    {
        $anthropic = new AnthropicClient(new MockHttpClient(), 'k');

        foreach (['anthropic', '', ' '] as $provider) {
            self::assertSame($anthropic, $this->create($provider, static fn() => $anthropic));
        }
    }

    public function testOpenAiCompatibleNeverBuildsTheAnthropicClient(): void
    {
        $client = $this->create(
            'openai_compatible',
            static fn() => self::fail('An instance on another provider must not need ANTHROPIC_API_KEY.'),
        );

        self::assertInstanceOf(OpenAiCompatibleClient::class, $client);
    }

    public function testAnUnknownProviderIsAClearError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown AI_PROVIDER "openia"');

        $this->create('openia', static fn() => new AnthropicClient(new MockHttpClient(), 'k'));
    }

    private function create(string $provider, \Closure $anthropic): object
    {
        return AiClientFactory::create(
            $provider,
            $anthropic,
            new MockHttpClient(),
            'http://localhost:11434/v1',
            '',
            'qwen',
            '',
        );
    }
}
