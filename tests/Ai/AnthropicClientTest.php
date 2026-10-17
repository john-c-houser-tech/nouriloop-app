<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\AiClient;
use App\Ai\AiFeature;
use App\Ai\AiResult;
use App\Ai\AnthropicClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AnthropicClientTest extends TestCase
{
    private const TURN = [['role' => 'user', 'content' => 'q']];

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
    }

    public function testImageCallSendsImagesThenPromptAsOneUserTurnWithNoSystemPrompt(): void
    {
        $first = $this->tempImage('first-bytes');
        $second = $this->tempImage('second-bytes');

        [$result, $request] = $this->call(
            fn(AiClient $c)
                => $c->completeWithImages(AiFeature::MealAnalysis, null, 'the prompt', [$first, $second], 2048),
            [
                'content' => [['type' => 'text', 'text' => 'reply']],
                'usage' => ['input_tokens' => 7, 'output_tokens' => 3],
            ],
        );

        self::assertSame('https://api.anthropic.com/v1/messages', $request['url']);
        self::assertContains('x-api-key: test-key', $request['headers']);
        self::assertContains('anthropic-version: 2023-06-01', $request['headers']);

        $body = $request['body'];
        self::assertSame(AnthropicClient::MODEL, $body['model']);
        self::assertSame(2048, $body['max_tokens']);
        self::assertArrayNotHasKey('system', $body);
        self::assertCount(1, $body['messages']);
        self::assertSame('user', $body['messages'][0]['role']);

        $content = $body['messages'][0]['content'];
        self::assertCount(3, $content);
        self::assertSame('first-bytes', base64_decode($content[0]['source']['data']));
        self::assertSame('second-bytes', base64_decode($content[1]['source']['data']));
        self::assertSame(['type' => 'text', 'text' => 'the prompt'], $content[2]);

        self::assertSame('reply', $result->text);
        self::assertSame(10, $result->totalTokens());
    }

    public function testMissingImagesAreDroppedAndTheRestSent(): void
    {
        $real = $this->tempImage('real-bytes');

        [, $request] = $this->call(
            fn(AiClient $c) => $c->completeWithImages(
                AiFeature::MealAnalysis,
                null,
                'p',
                ['/no/such/file.jpg', '', $real],
                1024,
            ),
        );

        $content = $request['body']['messages'][0]['content'];
        self::assertCount(2, $content);
        self::assertSame('real-bytes', base64_decode($content[0]['source']['data']));
    }

    public function testImagesGivenButNoneReadableThrowsBeforeAnyRequest(): void
    {
        $http = new MockHttpClient(function (): MockResponse {
            self::fail('No request may be sent.');
        });

        $this->expectException(\RuntimeException::class);
        (new AnthropicClient($http, 'test-key'))
            ->completeWithImages(AiFeature::MealAnalysis, null, 'p', ['/no/such/file.jpg'], 1024);
    }

    public function testNoImagesIsATextOnlyCallInBlockShape(): void
    {
        [, $request] = $this->call(
            fn(AiClient $c) => $c->completeWithImages(AiFeature::MealAnalysis, null, 'just text', [], 2048),
        );

        self::assertSame(
            [['type' => 'text', 'text' => 'just text']],
            $request['body']['messages'][0]['content'],
        );
    }

    public function testMissingUsageDefaultsToZeroTokens(): void
    {
        [$result] = $this->call(
            fn(AiClient $c) => $c->completeWithImages(AiFeature::MealAnalysis, null, 'p', [], 1024),
            ['content' => [['type' => 'text', 'text' => 'reply']]],
        );

        self::assertSame(0, $result->totalTokens());
    }

    public function testOversizedImageIsDownscaledForTheApiRequest(): void
    {
        // A >4 MB image: JPEG can't compress noise, so a tiled-noise 3500px
        // square at quality 100 lands well past the threshold.
        $tile = imagecreatetruecolor(100, 100);
        mt_srand(42);
        for ($x = 0; $x < 100; $x++) {
            for ($y = 0; $y < 100; $y++) {
                imagesetpixel($tile, $x, $y, (int) imagecolorallocate(
                    $tile,
                    mt_rand(0, 255),
                    mt_rand(0, 255),
                    mt_rand(0, 255),
                ));
            }
        }
        $big = imagecreatetruecolor(3500, 3500);
        for ($x = 0; $x < 3500; $x += 100) {
            for ($y = 0; $y < 3500; $y += 100) {
                imagecopy($big, $tile, $x, $y, 0, 0, 100, 100);
            }
        }
        $bigPath = tempnam(sys_get_temp_dir(), 'huge-meal') . '.jpg';
        $this->tempFiles[] = $bigPath;
        imagejpeg($big, $bigPath, 100);
        $originalSize = filesize($bigPath);
        self::assertGreaterThan(4_000_000, $originalSize, 'fixture must exceed the downscale threshold');

        [, $request] = $this->call(
            fn(AiClient $c) => $c->completeWithImages(AiFeature::MealAnalysis, null, 'p', [$bigPath], 2048),
        );

        $source = $request['body']['messages'][0]['content'][0]['source'];
        self::assertSame('image/jpeg', $source['media_type']);
        $sent = base64_decode($source['data']);
        self::assertLessThan($originalSize, strlen($sent), 'the API payload shrank');
        $sentIm = imagecreatefromstring($sent);
        self::assertNotFalse($sentIm);
        self::assertLessThanOrEqual(2000, max(imagesx($sentIm), imagesy($sentIm)));
    }

    public function testJsonToleratesFencesAndSurroundingProse(): void
    {
        $plain = new AiResult('  {"a": 1}  ', 0, 0);
        $fenced = new AiResult("Here you go:\n```json\n{\"a\": 1}\n```\nEnjoy.", 0, 0);

        self::assertSame(['a' => 1], $plain->json());
        self::assertSame(['a' => 1], $fenced->json());
    }

    public function testMainFeaturesUseTheDefaultModelAndLightOnesUseHaiku(): void
    {
        [, $main] = $this->call(fn(AiClient $c) => $c->complete(AiFeature::GoalCheckin, null, 's', self::TURN));
        [, $light] = $this->call(fn(AiClient $c) => $c->complete(AiFeature::HelpAssistant, null, 's', self::TURN));

        self::assertSame(AnthropicClient::MODEL, $main['body']['model']);
        self::assertSame(AnthropicClient::LIGHT_MODEL, $light['body']['model']);
    }

    public function testTheResultNamesTheProviderAndTheModelSent(): void
    {
        [$result] = $this->call(fn(AiClient $c) => $c->complete(AiFeature::HelpAssistant, null, 's', self::TURN));

        self::assertSame('anthropic', $result->provider);
        self::assertSame(AnthropicClient::LIGHT_MODEL, $result->model);
    }

    public function testConfiguredModelsReplaceTheDefaults(): void
    {
        $build = static fn(MockHttpClient $http): AnthropicClient
            => new AnthropicClient($http, 'k', 'main-x', 'light-y');

        [, $main] = $this->call(
            fn(AiClient $c) => $c->completeWithImages(AiFeature::MealAnalysis, null, 'p', [], 64),
            build: $build,
        );
        [, $light] = $this->call(
            fn(AiClient $c) => $c->complete(AiFeature::HelpAssistant, null, 's', self::TURN),
            build: $build,
        );

        self::assertSame('main-x', $main['body']['model']);
        self::assertSame('light-y', $light['body']['model']);
    }

    public function testJsonThrowsWhenNoObjectCanBeRecovered(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The model returned invalid JSON');

        (new AiResult('I could not read this, sorry!', 0, 0))->json();
    }

    /**
     * Runs $call against a client whose HTTP layer records the request.
     *
     * @param callable(AiClient): AiResult $call
     * @param array<string, mixed>                 $responseBody
     * @param (callable(MockHttpClient): AnthropicClient)|null $build client factory; default is a plain test client
     *
     * @return array{0: AiResult, 1: array{url: string, headers: list<string>, body: array<string, mixed>}}
     */
    private function call(callable $call, array $responseBody = [], ?callable $build = null): array
    {
        $responseBody = $responseBody ?: ['content' => [['type' => 'text', 'text' => '{}']]];
        $request = null;
        $http = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$request, $responseBody): MockResponse {
                $request = [
                    'url' => $url,
                    'headers' => $options['headers'],
                    'body' => json_decode((string) $options['body'], true),
                ];

                return new MockResponse((string) json_encode($responseBody));
            },
        );

        $result = $call($build !== null ? $build($http) : new AnthropicClient($http, 'test-key'));

        return [$result, $request];
    }

    private function tempImage(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'claude') . '.jpg';
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        return $path;
    }
}
