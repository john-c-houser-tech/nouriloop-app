<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\AiClient;
use App\Ai\AiFeature;
use App\Ai\AiResult;
use App\Ai\ResolvingAiClient;
use App\Ai\UserAiClientBuilder;
use App\Ai\UserAiProvider;
use App\Entity\User;
use App\Entity\UserAiSettings;
use App\Repository\UserAiSettingsRepository;
use App\Security\UserSecretBox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ResolvingAiClientTest extends TestCase
{
    private const TURN = [['role' => 'user', 'content' => 'q']];

    public function testWithoutAUserOrSettingsTheInstanceClientAnswers(): void
    {
        $instance = $this->createMock(AiClient::class);
        $instance->expects(self::exactly(2))->method('complete')
            ->willReturn(new AiResult('instance', 1, 1, 'anthropic', 'm'));

        $repo = $this->createStub(UserAiSettingsRepository::class);
        $repo->method('findForUser')->willReturn(null);
        $client = new ResolvingAiClient($instance, $repo, $this->builder(new MockHttpClient()));

        $anonymous = $client->complete(AiFeature::HelpAssistant, null, 's', self::TURN);
        $noSettings = $client->complete(AiFeature::GoalSetup, $this->user(), 's', self::TURN);

        self::assertFalse($anonymous->userKey);
        self::assertFalse($noSettings->userKey);
    }

    public function testAUserWithSettingsIsAnsweredByTheirProviderWithTheirKey(): void
    {
        $instance = $this->createMock(AiClient::class);
        $instance->expects(self::never())->method('completeWithImages');

        $sentKey = null;
        $record = static function (string $m, string $url, array $options) use (&$sentKey): MockResponse {
            foreach ($options['headers'] as $h) {
                if (str_starts_with(strtolower($h), 'x-api-key:')) {
                    $sentKey = trim(substr($h, \strlen('x-api-key:')));
                }
            }

            return new MockResponse((string) json_encode(['content' => [['type' => 'text', 'text' => '{}']]]));
        };
        $http = new MockHttpClient($record);

        $box = new UserSecretBox(base64_encode(random_bytes(32)));
        $settings = (new UserAiSettings($this->user(), UserAiProvider::Anthropic))
            ->setEncryptedKey($box->encrypt('sk-users-own'), 'sown')
            ->setVisionModel('claude-opus-5-5');
        $repo = $this->createStub(UserAiSettingsRepository::class);
        $repo->method('findForUser')->willReturn($settings);

        $result = (new ResolvingAiClient($instance, $repo, new UserAiClientBuilder($http, $box)))
            ->completeWithImages(AiFeature::MealAnalysis, $this->user(), 'p', [], 64);

        self::assertSame('sk-users-own', $sentKey);
        self::assertTrue($result->userKey);
        self::assertSame('claude-opus-5-5', $result->model, 'photo features use the vision slot');
    }

    private function user(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);

        return $user;
    }

    private function builder(MockHttpClient $http): UserAiClientBuilder
    {
        return new UserAiClientBuilder($http, new UserSecretBox(base64_encode(random_bytes(32))));
    }
}
