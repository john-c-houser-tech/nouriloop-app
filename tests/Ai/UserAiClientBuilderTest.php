<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\AiFeature;
use App\Ai\UserAiClientBuilder;
use App\Ai\UserAiProvider;
use App\Entity\User;
use App\Entity\UserAiSettings;
use App\Security\UserSecretBox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class UserAiClientBuilderTest extends TestCase
{
    private const TURN = [['role' => 'user', 'content' => 'q']];

    private UserSecretBox $box;

    protected function setUp(): void
    {
        $this->box = new UserSecretBox(base64_encode(random_bytes(32)));
    }

    public function testOpenRouterWithBlankModelsUsesNouriLoopsChoiceForTextAndPhotos(): void
    {
        $sent = [];
        $client = (new UserAiClientBuilder($this->recorder($sent), $this->box))
            ->build($this->settings(UserAiProvider::OpenRouter));

        $client->complete(AiFeature::HelpAssistant, null, 's', self::TURN);
        $client->completeWithImages(AiFeature::MealAnalysis, null, 'p', [], 64);

        $default = UserAiProvider::OpenRouter->defaultModel();
        self::assertSame([$default, $default], array_column($sent, 'model'));
        self::assertSame(UserAiProvider::OPENROUTER_BASE_URL . '/chat/completions', $sent[0]['url']);
    }

    public function testTheTextModelServesLightFeaturesAndTheVisionModelServesPhotos(): void
    {
        $sent = [];
        $settings = $this->settings(UserAiProvider::OpenRouter)->setTextModel('text-m')->setVisionModel('vision-m');
        $client = (new UserAiClientBuilder($this->recorder($sent), $this->box))->build($settings);

        $client->complete(AiFeature::HelpAssistant, null, 's', self::TURN);
        $client->complete(AiFeature::GoalCheckin, null, 's', self::TURN);
        $client->completeWithImages(AiFeature::RecipeAnalysis, null, 'p', [], 64);

        self::assertSame(['text-m', 'text-m', 'vision-m'], array_column($sent, 'model'));
    }

    public function testABlankAnthropicTextSlotMeansSonnetForHelpAnswersToo(): void
    {
        $models = [];
        $record = static function (string $m, string $url, array $options) use (&$models): MockResponse {
            $models[] = json_decode((string) $options['body'], true)['model'];

            return new MockResponse((string) json_encode(['content' => [['type' => 'text', 'text' => '{}']]]));
        };
        $http = new MockHttpClient($record);
        $client = (new UserAiClientBuilder($http, $this->box))->build($this->settings(UserAiProvider::Anthropic));

        $client->complete(AiFeature::HelpAssistant, null, 's', self::TURN);
        $client->complete(AiFeature::GoalCheckin, null, 's', self::TURN);

        $default = UserAiProvider::Anthropic->defaultModel();
        self::assertSame([$default, $default], $models, 'not the instance light model (Haiku)');
    }

    public function testACustomEndpointOnAPrivateAddressIsRefusedBeforeAnyRequest(): void
    {
        $sent = [];
        $settings = $this->settings(UserAiProvider::Custom)->setBaseUrl('https://192.168.1.20/v1')->setTextModel('m');
        $client = (new UserAiClientBuilder($this->recorder($sent), $this->box))->build($settings);

        try {
            $client->complete(AiFeature::GoalSetup, null, 's', self::TURN);
            self::fail('A private address must be refused.');
        } catch (TransportException) {
        }
        self::assertSame([], $sent, 'nothing reached the network');
    }

    private function settings(UserAiProvider $provider): UserAiSettings
    {
        return (new UserAiSettings($this->createStub(User::class), $provider))
            ->setEncryptedKey($this->box->encrypt('sk-test'), 'test');
    }

    /** @param list<array{url: string, model: string}> $sent */
    private function recorder(array &$sent): MockHttpClient
    {
        return new MockHttpClient(static function (string $m, string $url, array $options) use (&$sent): MockResponse {
            $sent[] = ['url' => $url, 'model' => json_decode((string) $options['body'], true)['model']];

            return new MockResponse((string) json_encode(['choices' => [['message' => ['content' => '{}']]]]));
        });
    }
}
