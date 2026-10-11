<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use App\Tests\Http\FakeAnthropicResponder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The analysis-failure retry flow (in-process, real DB): a failed Claude call
 * keeps the uploaded photo on disk and offers a one-click "Retry analysis"
 * instead of forcing a re-upload. The failure is simulated through the meal
 * notes — they flow into the analysis prompt (the notes-to-AI feature), which
 * is where FakeAnthropicResponder's trigger markers are matched, so these tests
 * also prove notes reach the prompt end-to-end.
 */
final class MealAnalysisRetryTest extends WebTestCase
{
    use DbResetTrait;

    protected function setUp(): void
    {
        parent::setUp();
        FakeAnthropicResponder::resetFailureTrigger();
    }

    public function testFailedAnalysisParksPhotoAndRetrySucceeds(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $client->loginUser($this->seedUser($em, 'retry@test.local'));

        $crawler = $this->submitMeal($client, FakeAnthropicResponder::FAIL_ONCE . ' extra crispy');

        // Failure → back on the log page with the retry card, photo parked.
        self::assertStringContainsString('no need to upload it again', $crawler->html());
        $parkedFile = $this->parkedFilePath($crawler);
        self::assertFileExists($parkedFile, 'the upload survives the failure');

        // One click retries with the kept photo — the fake now succeeds.
        $client->submit($crawler->filter('#retry-form')->form());
        $this->assertResponseRedirects('/meal/clarify');
        $crawler = $client->followRedirect();
        self::assertStringContainsString('Grilled chicken breast', $crawler->html());

        // The parked state is consumed: a fresh log page shows no retry card.
        $crawler = $client->request('GET', '/meal/log');
        self::assertCount(0, $crawler->filter('#retry-form'));
    }

    public function testRetryCardPersistsWhileAnalysisKeepsFailing(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $client->loginUser($this->seedUser($em, 'stillbroken@test.local'));

        $crawler = $this->submitMeal($client, FakeAnthropicResponder::FAIL_ALWAYS);
        self::assertCount(1, $crawler->filter('#retry-form'));

        $client->submit($crawler->filter('#retry-form')->form());
        $this->assertResponseRedirects('/meal/log');
        $crawler = $client->followRedirect();
        self::assertCount(1, $crawler->filter('#retry-form'), 'still failing → still parked');
    }

    public function testFreshSuccessfulAnalysisDropsTheParkedPhoto(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $client->loginUser($this->seedUser($em, 'freshstart@test.local'));

        $crawler = $this->submitMeal($client, FakeAnthropicResponder::FAIL_ALWAYS);
        $parkedFile = $this->parkedFilePath($crawler);
        self::assertFileExists($parkedFile);

        // The user gives up on that photo and logs a different meal successfully.
        $this->submitMeal($client, 'just a salad', followTo: '/meal/clarify');

        self::assertFileDoesNotExist($parkedFile, 'the abandoned photo is cleaned up');
        $crawler = $client->request('GET', '/meal/log');
        self::assertCount(0, $crawler->filter('#retry-form'));
    }

    /**
     * Submits the log form with a generated JPEG and the given notes. Returns
     * the crawler after following the redirect (to /meal/log on failure, or to
     * $followTo when given).
     */
    private function submitMeal(
        \Symfony\Bundle\FrameworkBundle\KernelBrowser $client,
        string $notes,
        string $followTo = '/meal/log',
    ): \Symfony\Component\DomCrawler\Crawler {
        $crawler = $client->request('GET', '/meal/log');
        $form = $crawler->filter('#meal-form')->form();
        $form['meal_entry_form[notes]'] = $notes;
        // The image field is `multiple` — the crawler exposes it as an array.
        $form['meal_entry_form[image]'][0]->upload($this->makeJpeg());
        $client->submit($form);

        $this->assertResponseRedirects($followTo);

        return $client->followRedirect();
    }

    /** Server-side path of the photo shown on the retry card. */
    private function parkedFilePath(\Symfony\Component\DomCrawler\Crawler $crawler): string
    {
        $src = (string) $crawler->filter('#retry-form')->closest('.card')->filter('img')->attr('src');
        $uploadsDir = static::getContainer()->getParameter('uploads_dir');

        return $uploadsDir . '/' . basename($src);
    }

    private function makeJpeg(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'retry-meal') . '.jpg';
        $im = imagecreatetruecolor(24, 24);
        imagefilledrectangle($im, 0, 0, 23, 23, (int) imagecolorallocate($im, 200, 120, 40));
        imagejpeg($im, $path);

        return $path;
    }

    private function seedUser(EntityManagerInterface $em, string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Retry Tester');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        // Verified per the fixture rule (see CLAUDE.md) so the verification
        // banner's form can't confuse crawler selectors.
        $user->setEmailVerifiedAt(new \DateTimeImmutable('-1 day'));
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
