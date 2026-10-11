<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\MealEntry;
use App\Entity\User;
use App\Service\ClarifyRound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Confirm & Refine re-analysis rounds are capped (like the recipe import's):
 * each round is a billable call the derived monthly allowance can't see, since
 * the meal isn't saved yet. In-process `WebTestCase` with Claude mocked; the
 * kernel is kept across requests so the mock's request count accumulates.
 */
final class MealClarifyRoundCapTest extends WebTestCase
{
    use DbResetTrait;

    public function testReanalysisStopsAtTheRoundCapAndTheMealStillSaves(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $client->loginUser($this->seedUser($em));

        $this->logDescribedMeal($client);
        self::assertSame(1, $this->anthropic()->getRequestsCount(), 'round 1 = the initial analysis');

        // Rounds 2 and 3 are allowed…
        for ($round = 2; $round <= ClarifyRound::MAX_ROUNDS; $round++) {
            $this->postClarify($client, []);
            self::assertResponseRedirects('/meal/clarify');
        }
        self::assertSame(ClarifyRound::MAX_ROUNDS, $this->anthropic()->getRequestsCount());

        // …then the re-analyze button is gone, with a note saying why…
        $crawler = $client->request('GET', '/meal/clarify');
        self::assertCount(1, $crawler->filter('#clarify-round-limit'));
        self::assertCount(0, $crawler->filter('button[type="submit"]:not([name])'));
        self::assertCount(0, $crawler->filter('input[name="extra_photo"]'));

        // …a stale or crafted re-analyze post makes no call…
        $this->postClarify($client, []);
        self::assertResponseRedirects('/meal/clarify');
        self::assertSame(ClarifyRound::MAX_ROUNDS, $this->anthropic()->getRequestsCount());

        // …and saving still works.
        $this->postClarify($client, ['accept' => '1', 'portions' => '1']);
        $em->clear();
        self::assertCount(1, $em->getRepository(MealEntry::class)->findAll());
    }

    private function logDescribedMeal(KernelBrowser $client): void
    {
        $crawler = $client->request('GET', '/meal/log');
        $token = (string) $crawler->filter('#meal-form input[name="meal_entry_form[_token]"]')->attr('value');

        $client->request('POST', '/meal/log', ['meal_entry_form' => [
            'mealType'    => 'lunch',
            'description' => 'grilled salmon with rice and broccoli',
            'notes'       => '',
            '_token'      => $token,
        ]]);
        self::assertResponseRedirects('/meal/clarify');
    }

    /** @param array<string, string> $fields */
    private function postClarify(KernelBrowser $client, array $fields): Crawler
    {
        $crawler = $client->request('GET', '/meal/clarify');
        $token = (string) $crawler->filter('form[action="/meal/clarify"] input[name="_token"]')->attr('value');

        return $client->request('POST', '/meal/clarify', array_merge(['_token' => $token], $fields));
    }

    private function anthropic(): MockHttpClient
    {
        $mock = static::getContainer()->get('test.anthropic_http_client');
        self::assertInstanceOf(MockHttpClient::class, $mock);

        return $mock;
    }

    private function seedUser(EntityManagerInterface $em): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('rounds@test.local');
        $user->setDisplayName('Rounds Tester');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable('-1 day'));
        $user->setOnboardingDismissed(true);
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
