<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\AnalysisEvent;
use App\Entity\MealEntry;
use App\Entity\NutritionData;
use App\Entity\User;
use App\Enum\AnalysisKind;
use App\Enum\MealType;
use App\Enum\SubscriptionTier;
use App\Form\SupplementPhotoFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Supplement label scans draw on the shared Free-tier AnalysisAllowance (like
 * meal photos and recipe imports) and are booked on the call-time ledger, since
 * a scan creates no countable row until — unless — the user saves it. In-process
 * `WebTestCase` against the real DB with Claude mocked.
 */
final class SupplementScanAllowanceTest extends WebTestCase
{
    use DbResetTrait;

    public function testExhaustedFreeAllowanceBlocksTheScanBeforeAnyClaudeCall(): void
    {
        [$client, $em, $user] = $this->signedIn();
        for ($i = 0; $i < 10; $i++) {
            $this->seedMeal($em, $user, $i);
        }

        $this->submitScan($client, [$this->makeJpeg(120)]);

        self::assertResponseRedirects('/pricing');
        self::assertSame(0, $this->anthropic()->getRequestsCount(), 'the gate fires before the API is touched');
        self::assertCount(0, $em->getRepository(AnalysisEvent::class)->findAll());
    }

    public function testAScanIsLedgeredEvenIfNeverSavedAndSpendsTheLastSlot(): void
    {
        [$client, $em, $user] = $this->signedIn();
        for ($i = 0; $i < 9; $i++) {
            $this->seedMeal($em, $user, $i);
        }

        // The page shows the shared counter: 1 of 10 left.
        $crawler = $client->request('GET', '/supplements/scan');
        self::assertStringContainsString('1 of 10', $crawler->filter('.card')->text());

        $this->submitScan($client, [$this->makeJpeg(70)]);
        self::assertResponseRedirects('/supplements/confirm');

        $em->clear();
        $events = $em->getRepository(AnalysisEvent::class)->findAll();
        self::assertCount(1, $events);
        self::assertSame(AnalysisKind::SupplementScan, $events[0]->getKind());
        self::assertSame(1500, $events[0]->getTokens(), 'input + output tokens from the response');

        // Abandoning the confirm step doesn't refund it: the next scan is blocked.
        $this->submitScan($client, [$this->makeJpeg(75)]);
        self::assertResponseRedirects('/pricing');
    }

    public function testPaidUserIsUnlimitedButStillLedgered(): void
    {
        [$client, $em, $user] = $this->signedIn();
        $user->setCompTier(SubscriptionTier::Loop);
        for ($i = 0; $i < 10; $i++) {
            $this->seedMeal($em, $user, $i);
        }

        $this->submitScan($client, [$this->makeJpeg(90)]);

        self::assertResponseRedirects('/supplements/confirm');
        $em->clear();
        self::assertCount(1, $em->getRepository(AnalysisEvent::class)->findAll());
    }

    public function testMoreThanThreePhotosIsRejectedBeforeAnyClaudeCall(): void
    {
        [$client] = $this->signedIn();
        $files = [];
        for ($i = 0; $i <= SupplementPhotoFormType::MAX_PHOTOS; $i++) {
            $files[] = $this->makeJpeg(40 + $i);
        }

        $crawler = $this->submitScan($client, $files);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('at most 3 photos', $crawler->filter('.card')->text());
        self::assertSame(0, $this->anthropic()->getRequestsCount());
    }

    /** @return array{KernelBrowser, EntityManagerInterface, User} */
    private function signedIn(): array
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('scanner@test.local');
        $user->setDisplayName('Scanner');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable('-1 day'));
        $user->setOnboardingDismissed(true);
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);

        return [$client, $em, $user];
    }

    /** @param list<string> $files */
    private function submitScan(KernelBrowser $client, array $files): Crawler
    {
        $crawler = $client->request('GET', '/supplements/scan');
        $token = (string) $crawler->filter('input[name="supplement_photo_form[_token]"]')->attr('value');

        return $client->request(
            'POST',
            '/supplements/scan',
            ['supplement_photo_form' => ['_token' => $token]],
            ['supplement_photo_form' => [
                'images' => array_map(
                    static fn(string $p): UploadedFile => new UploadedFile($p, basename($p), 'image/jpeg', null, true),
                    $files,
                ),
            ]],
        );
    }

    private function anthropic(): MockHttpClient
    {
        $mock = static::getContainer()->get('test.anthropic_http_client');
        self::assertInstanceOf(MockHttpClient::class, $mock);

        return $mock;
    }

    private function makeJpeg(int $shade): string
    {
        $path = tempnam(sys_get_temp_dir(), 'supp') . '.jpg';
        $im = imagecreatetruecolor(24, 24);
        imagefilledrectangle($im, 0, 0, 23, 23, (int) imagecolorallocate($im, $shade, 90, 60));
        imagejpeg($im, $path);

        return $path;
    }

    /** A meal inside the user's current local month (see RecipeImportTest::seedMeal). */
    private function seedMeal(EntityManagerInterface $em, User $user, int $offsetMinutes): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone($user->getTimezone()));
        $entry = new MealEntry();
        $entry->setUser($user);
        $entry->setMealType(MealType::Lunch);
        $entry->setLoggedAt(
            $now->setDate((int) $now->format('Y'), (int) $now->format('n'), 1)
                ->setTime(12, 0)
                ->modify(sprintf('+%d minutes', $offsetMinutes)),
        );
        $entry->setNutritionData(NutritionData::fromArray(['calories' => 500, 'confidenceScore' => 0.9]));
        $em->persist($entry);
        $em->flush();
    }
}
