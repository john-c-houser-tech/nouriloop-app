<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Controller\GoalConversationController;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cost bounds on the goal-setup chat (free on every tier, and every turn resends
 * the whole transcript): a per-message length cap, a per-conversation user-turn
 * cap, and the `goal_chat` rate limiter. Each blocked message must never reach
 * Claude. In-process `WebTestCase` with Claude mocked; the kernel is kept across
 * requests so the mock's request count accumulates.
 */
final class GoalChatLimitsTest extends WebTestCase
{
    use DbResetTrait;

    public function testTooLongMessageNeverReachesClaude(): void
    {
        $client = $this->signedIn('long@test.local');

        $this->send($client, str_repeat('a', GoalConversationController::MAX_MESSAGE_LENGTH + 1));

        self::assertResponseRedirects('/goals/new');
        $crawler = $client->followRedirect();
        self::assertStringContainsString('under 1000 characters', $crawler->html());
        self::assertSame(0, $this->anthropic()->getRequestsCount());
    }

    public function testConversationStopsAtTheTurnCapUntilStartedOver(): void
    {
        $client = $this->signedIn('turns@test.local');

        for ($i = 1; $i <= GoalConversationController::MAX_USER_TURNS; $i++) {
            $this->send($client, 'more fiber please, turn ' . $i);
        }
        self::assertSame(GoalConversationController::MAX_USER_TURNS, $this->anthropic()->getRequestsCount());

        // The input is replaced by the start-over notice…
        $crawler = $client->request('GET', '/goals/new');
        self::assertCount(1, $crawler->filter('#chat-turn-limit'));
        self::assertCount(0, $crawler->filter('form[action="/goals/new/message"]'));

        // …and a stale or crafted post is refused without a call.
        $this->send($client, 'one more');
        self::assertSame(GoalConversationController::MAX_USER_TURNS, $this->anthropic()->getRequestsCount());
        self::assertStringContainsString('gotten long', $client->followRedirect()->html());

        // Starting over brings the input back.
        $this->startOver($client);
        $crawler = $client->request('GET', '/goals/new');
        self::assertCount(1, $crawler->filter('form[action="/goals/new/message"]'));
    }

    public function testRateLimiterStopsTheTwentyFirstMessageInAnHour(): void
    {
        $client = $this->signedIn('busy@test.local');

        for ($i = 1; $i <= 20; $i++) {
            $this->send($client, 'message ' . $i);
            if ($i === GoalConversationController::MAX_USER_TURNS) {
                $this->startOver($client); // stay under the turn cap
            }
        }
        self::assertSame(20, $this->anthropic()->getRequestsCount());

        $this->send($client, 'message 21');

        self::assertSame(20, $this->anthropic()->getRequestsCount(), 'the 21st message never reaches Claude');
        self::assertStringContainsString('a lot of messages', $client->followRedirect()->html());
    }

    private function signedIn(string $email): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Chatter');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable('-1 day'));
        $user->setOnboardingDismissed(true);
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        // Limiter state lives in the persistent cache pool — reset it so an earlier
        // run (or test) can't leave this user's hourly window already spent.
        static::getContainer()->get('limiter.goal_chat')->create('goal-chat-user-' . $email)->reset();

        return $client;
    }

    private function send(KernelBrowser $client, string $message): void
    {
        $client->request('POST', '/goals/new/message', [
            '_token'  => $this->chatToken($client),
            'message' => $message,
        ]);
    }

    private function startOver(KernelBrowser $client): void
    {
        $client->request('POST', '/goals/new/reset', ['_token' => $this->chatToken($client)]);
    }

    /** The `goal-chat` token, read from whichever chat form is on the page. */
    private function chatToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/goals/new');
        $forms = $crawler->filter('form[action="/goals/new/message"], form[action="/goals/new/reset"]');
        self::assertGreaterThan(0, $forms->count());

        return (string) $forms->first()->filter('input[name="_token"]')->attr('value');
    }

    private function anthropic(): MockHttpClient
    {
        $mock = static::getContainer()->get('test.anthropic_http_client');
        self::assertInstanceOf(MockHttpClient::class, $mock);

        return $mock;
    }
}
