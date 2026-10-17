<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\AnthropicClient;
use App\Ai\ModelPrices;
use PHPUnit\Framework\TestCase;

final class ModelPricesTest extends TestCase
{
    public function testSonnetCostsThreeAndFifteenDollarsPerMillionTokens(): void
    {
        // 3,374 tokens split 2,530 / 844: John's average meal analysis (Plan Q2b).
        self::assertSame(2530 * 3 + 844 * 15, ModelPrices::costMicros('anthropic', AnthropicClient::MODEL, 2530, 844));
    }

    public function testTheAppsDefaultModelsAreBothPriced(): void
    {
        self::assertNotNull(ModelPrices::costMicros('anthropic', AnthropicClient::MODEL, 1, 1));
        self::assertNotNull(ModelPrices::costMicros('anthropic', AnthropicClient::LIGHT_MODEL, 1, 1));
    }

    public function testAnUnlistedModelHasNoEstimateRatherThanAGuess(): void
    {
        self::assertNull(ModelPrices::costMicros('127.0.0.1:11434', 'qwen2.5:7b', 1000, 1000));
    }

    public function testAnAnthropicModelIdThroughAnotherProviderIsNotGivenAnthropicsPrice(): void
    {
        // Copilot catch on #242: a proxy or router may pass the same model id through,
        // at its own price, so only Anthropic's own calls get Anthropic's rate.
        self::assertNull(ModelPrices::costMicros('openrouter.ai', AnthropicClient::MODEL, 1000, 1000));
        self::assertNull(ModelPrices::costMicros('my-proxy.internal:8080', AnthropicClient::LIGHT_MODEL, 1000, 1000));
    }
}
