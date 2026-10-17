<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\AiFeature;
use App\Ai\AiResult;
use App\Ai\OpenAiCompatibleClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenAiCompatibleClientTest extends TestCase
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

    public function testCompleteSendsTheSystemPromptFirstToChatCompletions(): void
    {
        [$result, $request] = $this->call(
            fn(OpenAiCompatibleClient $c) => $c->complete(
                AiFeature::GoalSetup,
                null,
                'You are a coach.',
                [['role' => 'user', 'content' => 'Fiber?'], ['role' => 'assistant', 'content' => 'Sure.']],
                900,
            ),
            [
                'choices' => [['message' => ['content' => 'Eat beans.']]],
                'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 3],
            ],
        );

        self::assertSame('https://example.test/v1/chat/completions', $request['url']);
        self::assertSame('main-model', $request['body']['model']);
        self::assertSame(900, $request['body']['max_tokens']);
        self::assertSame([
            ['role' => 'system', 'content' => 'You are a coach.'],
            ['role' => 'user', 'content' => 'Fiber?'],
            ['role' => 'assistant', 'content' => 'Sure.'],
        ], $request['body']['messages']);
        self::assertContains('authorization: bearer sk-test', array_map('strtolower', $request['headers']));

        self::assertSame('Eat beans.', $result->text);
        self::assertSame(12, $result->inputTokens);
        self::assertSame(3, $result->outputTokens);
    }

    public function testImagesGoAsDataUriPartsBeforeThePrompt(): void
    {
        $png = $this->tempImage(base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
            true,
        ) ?: '');

        [, $request] = $this->call(
            fn(OpenAiCompatibleClient $c)
                => $c->completeWithImages(AiFeature::MealAnalysis, null, 'What is this?', [$png], 2048),
        );

        $content = $request['body']['messages'][0]['content'];
        self::assertSame('user', $request['body']['messages'][0]['role']);
        self::assertCount(2, $content);
        self::assertSame('image_url', $content[0]['type']);
        self::assertStringStartsWith('data:image/png;base64,', $content[0]['image_url']['url']);
        self::assertSame(['type' => 'text', 'text' => 'What is this?'], $content[1]);
        self::assertCount(1, $request['body']['messages'], 'an image call has no system message');
    }

    public function testUnreadableImagesAreRefusedBeforeAnyRequest(): void
    {
        $http = new MockHttpClient(static function (): MockResponse {
            self::fail('No request may be sent when no image can be read.');
        });

        $this->expectException(\RuntimeException::class);
        (new OpenAiCompatibleClient($http, 'https://example.test/v1', '', 'm'))
            ->completeWithImages(AiFeature::SupplementScan, null, 'p', ['/no/such/file.jpg'], 64);
    }

    public function testABlankKeySendsNoAuthorizationHeader(): void
    {
        [, $request] = $this->call(
            fn(OpenAiCompatibleClient $c)
                => $c->complete(AiFeature::Predict, null, '', [['role' => 'user', 'content' => 'x']]),
            build: static fn(MockHttpClient $http)
                => new OpenAiCompatibleClient($http, 'http://localhost:11434/v1/', '', 'qwen'),
        );

        foreach ($request['headers'] as $header) {
            self::assertStringStartsNotWith('authorization', strtolower($header));
        }
        self::assertSame(
            'http://localhost:11434/v1/chat/completions',
            $request['url'],
            'a trailing slash is tolerated',
        );
        self::assertSame(
            [['role' => 'user', 'content' => 'x']],
            $request['body']['messages'],
            'an empty system prompt is omitted',
        );
    }

    public function testLightFeaturesUseTheLightModelFallingBackToTheMainOne(): void
    {
        [, $withLight] = $this->call(
            fn(OpenAiCompatibleClient $c) => $c->complete(AiFeature::HelpAssistant, null, 's', self::TURN),
        );
        [, $withoutLight] = $this->call(
            fn(OpenAiCompatibleClient $c) => $c->complete(AiFeature::HelpAssistant, null, 's', self::TURN),
            build: static fn(MockHttpClient $http)
                => new OpenAiCompatibleClient($http, 'https://example.test/v1', 'k', 'only-model'),
        );

        self::assertSame('light-model', $withLight['body']['model']);
        self::assertSame('only-model', $withoutLight['body']['model']);
    }

    public function testListShapedContentIsJoinedAndMissingUsageIsZero(): void
    {
        [$result] = $this->call(
            fn(OpenAiCompatibleClient $c) => $c->complete(AiFeature::GlucoseCoach, null, 's', self::TURN),
            ['choices' => [['message' => ['content' => [
                ['type' => 'text', 'text' => '{"a":'],
                ['type' => 'text', 'text' => ' 1}'],
            ]]]]],
        );

        self::assertSame(['a' => 1], $result->json());
        self::assertSame(0, $result->totalTokens());
    }

    public function testTheResultNamesTheEndpointHostAndTheModelSent(): void
    {
        [$remote] = $this->call(
            fn(OpenAiCompatibleClient $c) => $c->complete(AiFeature::HelpAssistant, null, 's', self::TURN),
        );
        [$local] = $this->call(
            fn(OpenAiCompatibleClient $c) => $c->complete(AiFeature::Predict, null, 's', self::TURN),
            build: static fn(MockHttpClient $http)
                => new OpenAiCompatibleClient($http, 'http://127.0.0.1:11434/v1', '', 'qwen'),
        );

        self::assertSame(['example.test', 'light-model'], [$remote->provider, $remote->model]);
        self::assertSame(['127.0.0.1:11434', 'qwen'], [$local->provider, $local->model]);
    }

    public function testABaseUrlAndModelAreRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OpenAiCompatibleClient(new MockHttpClient(), 'https://example.test/v1', 'k', ' ');
    }

    /**
     * Runs $call against a client whose HTTP layer records the request.
     *
     * @param callable(OpenAiCompatibleClient): AiResult                $call
     * @param array<string, mixed>                                       $responseBody
     * @param (callable(MockHttpClient): OpenAiCompatibleClient)|null    $build
     *
     * @return array{0: AiResult, 1: array{url: string, headers: list<string>, body: array<string, mixed>}}
     */
    private function call(callable $call, array $responseBody = [], ?callable $build = null): array
    {
        $responseBody = $responseBody ?: ['choices' => [['message' => ['content' => '{}']]]];
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

        $client = $build !== null
            ? $build($http)
            : new OpenAiCompatibleClient($http, 'https://example.test/v1', 'sk-test', 'main-model', 'light-model');

        return [$call($client), $request];
    }

    private function tempImage(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'oai') . '.png';
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        return $path;
    }
}
