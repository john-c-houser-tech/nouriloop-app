<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Ai\UserAiProvider;
use App\Ai\VisionBenchmark;
use App\Command\AiBenchmarkCommand;
use App\Entity\User;
use App\Entity\UserAiSettings;
use App\Security\UserSecretBox;
use App\Tests\Http\FakeAnthropicResponder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The vision benchmark (Plan PR 5, decision Q10): five reference photos through the
 * user's own photo model, one request per photo; a failure warns on the meal review and
 * blocks nothing. FakeAnthropicResponder answers each benchmark photo with its reference
 * values, or 50 % high when the model name contains BENCHMARK_FAIL_MODEL.
 */
final class AiBenchmarkFlowTest extends WebTestCase
{
    use DbResetTrait;

    public function testSavingANewPhotoModelStartsACheckButSavingOnlyAKeyDoesNot(): void
    {
        [$client] = $this->signedIn(withSettings: false);

        $this->saveSettings($client, [
            'provider' => 'anthropic', 'api_key' => 'sk-test-first', 'vision_model' => 'claude-opus-5-5',
        ]);
        $client->followRedirect();
        self::assertSame('1', $client->getCrawler()->filter('#ai-benchmark')->attr('data-autostart'));

        $this->saveSettings($client, [
            'provider' => 'anthropic', 'api_key' => 'sk-test-second', 'vision_model' => 'claude-opus-5-5',
        ]);
        $client->followRedirect();
        self::assertSame('0', $client->getCrawler()->filter('#ai-benchmark')->attr('data-autostart'));
    }

    public function testAPassingModelPassesOnePhotoPerRequest(): void
    {
        [$client] = $this->signedIn(visionModel: 'claude-opus-5-5');

        $last = $this->runAllCases($client);
        self::assertSame(VisionBenchmark::caseCount(), $last['done']);
        self::assertTrue($last['passed']);

        $client->request('GET', '/settings/ai');
        self::assertSelectorTextContains('#ai-benchmark-status', 'Passed.');
        self::assertSame(
            ['vision_benchmark' => VisionBenchmark::caseCount()],
            array_column($this->conn()->fetchAllAssociative(
                'SELECT feature, COUNT(*) AS n FROM ai_call WHERE user_key = 1 GROUP BY feature',
            ), 'n', 'feature'),
        );
    }

    public function testAFailingModelShowsItsMissesAndWarnsOnTheMealReviewWithoutBlocking(): void
    {
        [$client] = $this->signedIn(visionModel: 'claude-' . FakeAnthropicResponder::BENCHMARK_FAIL_MODEL);

        $last = $this->runAllCases($client);
        self::assertFalse($last['passed']);

        $client->request('GET', '/settings/ai');
        self::assertSelectorTextContains('#ai-benchmark-status', "Didn't pass.");
        self::assertSelectorTextContains('#ai-benchmark-results', 'Calories: expected 330 kcal, got 495 kcal');

        // Photo analysis still works; the review just says the model didn't pass.
        $crawler = $client->request('GET', '/meal/log');
        $token = (string) $crawler->filter('#meal-form input[name="meal_entry_form[_token]"]')->attr('value');
        $client->request('POST', '/meal/log', ['meal_entry_form' => [
            'mealType' => 'lunch', 'description' => 'a bowl of soup', 'notes' => '', '_token' => $token,
        ]]);
        self::assertResponseRedirects('/meal/clarify');
        $client->followRedirect();
        self::assertSelectorTextContains('#photo-model-warning', "didn't pass NouriLoop's photo check");
    }

    public function testThePassingCaseHasNoMealReviewWarning(): void
    {
        [$client] = $this->signedIn(visionModel: 'claude-opus-5-5');
        $this->runAllCases($client);

        $crawler = $client->request('GET', '/meal/log');
        $token = (string) $crawler->filter('#meal-form input[name="meal_entry_form[_token]"]')->attr('value');
        $client->request('POST', '/meal/log', ['meal_entry_form' => [
            'mealType' => 'lunch', 'description' => 'a bowl of soup', 'notes' => '', '_token' => $token,
        ]]);
        $client->followRedirect();
        self::assertSelectorNotExists('#photo-model-warning');
    }

    public function testARerunThatStopsPartwayGivesNoVerdictFromTheOldRun(): void
    {
        // Copilot catch on #244: photo 0 starts a fresh run, so old cases can't fill it out.
        [$client] = $this->signedIn(visionModel: 'claude-opus-5-5');
        self::assertTrue($this->runAllCases($client)['passed']);

        $client->request('POST', '/settings/ai/benchmark/0', ['_token' => $this->benchmarkToken($client)]);
        $after = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(1, $after['done']);
        self::assertNull($after['passed']);
    }

    public function testProgressIsAnnouncedToScreenReaders(): void
    {
        [$client] = $this->signedIn(visionModel: 'claude-opus-5-5');
        $crawler = $client->request('GET', '/settings/ai');
        self::assertSame('status', $crawler->filter('#ai-benchmark-status')->attr('role'));
        self::assertSame('polite', $crawler->filter('#ai-benchmark-status')->attr('aria-live'));
    }

    public function testTheNoScriptFallbackRunsAllFivePhotos(): void
    {
        [$client] = $this->signedIn(visionModel: 'claude-opus-5-5');

        $crawler = $client->request('GET', '/settings/ai');
        $client->submit($crawler->filter('#ai-benchmark-form')->form());
        self::assertResponseRedirects('/settings/ai');
        $client->followRedirect();
        self::assertSelectorTextContains('#ai-benchmark-status', 'Passed.');
    }

    public function testAUserWithoutTheirOwnKeyCannotRunIt(): void
    {
        [$client] = $this->signedIn(withSettings: false);
        $client->request('GET', '/settings/ai');
        self::assertSelectorNotExists('#ai-benchmark');

        $client->request('POST', '/settings/ai/benchmark/0', ['_token' => 'any']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheOperatorCommandPassesOnTheInstanceModel(): void
    {
        self::bootKernel();
        $tester = new CommandTester(static::getContainer()->get(AiBenchmarkCommand::class));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('All cases passed.', $tester->getDisplay());
    }

    /** @return array{done: int, total: int, passed: bool|null} the last response */
    private function runAllCases(KernelBrowser $client): array
    {
        $token = $this->benchmarkToken($client);
        $last = [];
        for ($i = 0; $i < VisionBenchmark::caseCount(); $i++) {
            $client->request('POST', '/settings/ai/benchmark/' . $i, ['_token' => $token]);
            self::assertResponseIsSuccessful();
            $last = json_decode((string) $client->getResponse()->getContent(), true);
        }

        return $last;
    }

    private function benchmarkToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/settings/ai');

        return (string) $crawler->filter('#ai-benchmark-form input[name="_token"]')->attr('value');
    }

    /** @param array<string, string> $values */
    private function saveSettings(KernelBrowser $client, array $values): void
    {
        $crawler = $client->request('GET', '/settings/ai');
        $client->submit(
            $crawler->filter('#ai-form')->form(),
            $values + ['api_key' => '', 'base_url' => '', 'text_model' => '', 'vision_model' => ''],
        );
        self::assertResponseRedirects('/settings/ai');
    }

    /** @return array{0: KernelBrowser, 1: User} */
    private function signedIn(bool $withSettings = true, string $visionModel = ''): array
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $user = new User();
        $user->setEmail('bench@test.local');
        $user->setDisplayName('Bench');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable('-1 day'));
        $user->setOnboardingDismissed(true);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $em->persist($user);

        if ($withSettings) {
            $settings = (new UserAiSettings($user, UserAiProvider::Anthropic))
                ->setVisionModel($visionModel !== '' ? $visionModel : null)
                ->setEncryptedKey(static::getContainer()->get(UserSecretBox::class)->encrypt('sk-test-own'), 'town')
                ->resetBenchmark();
            $em->persist($settings);
        }
        $em->flush();

        static::getContainer()->get('limiter.ai_benchmark')->create('ai-benchmark-user-' . $user->getId())->reset();
        $client->loginUser($user);

        return [$client, $user];
    }

    private function conn(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
