<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\Favorite;
use App\Entity\MealEntry;
use App\Entity\User;
use App\Enum\SubscriptionTier;
use App\Tests\Http\FakeAnthropicResponder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The Wave-2 log-page paths (in-process, real DB, mocked Claude): multi-photo
 * upload (dish + label in ONE analysis call), the photo-free "describe your
 * meal" path, the either-photo-or-text validation, and the Free-tier allowance
 * counter.
 */
final class MealLogPathsTest extends WebTestCase
{
    use DbResetTrait;

    public function testTwoPhotosAnalyzeTogetherAndExtraIsDroppedOnSave(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $client->loginUser($this->seedUser($em, 'twophotos@test.local'));

        $crawler = $this->submitLog($client, files: [$this->makeJpeg(40), $this->makeJpeg(80)]);
        $this->assertResponseRedirects('/meal/clarify');
        $crawler = $client->followRedirect();

        // The second photo shows as an "added photo" thumbnail on the review.
        $extras = $crawler->filter('img[alt="Added photo"]');
        self::assertCount(1, $extras);
        $uploadsDir = static::getContainer()->getParameter('uploads_dir');
        $extraFile = $uploadsDir . '/' . basename((string) $extras->attr('src'));
        self::assertFileExists($extraFile);

        $client->submit($crawler->selectButton('Save to log')->form());
        $client->followRedirect();

        $em->clear();
        $meal = $em->getRepository(MealEntry::class)->findOneBy([]);
        self::assertNotNull($meal->getImagePath(), 'the first photo is the thumbnail');
        self::assertFileExists($uploadsDir . '/' . basename($meal->getImagePath()));
        self::assertFileDoesNotExist($extraFile, 'the label photo was an analysis aid, not kept');
    }

    public function testAnExtraPhotoThatIsNotAnImageSaysSoAndIsNotKept(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $client->loginUser($this->seedUser($em, 'badextra@test.local'));

        $this->submitLog($client, files: [$this->makeJpeg(40)]);
        $crawler = $client->followRedirect();
        $uploadsDir = static::getContainer()->getParameter('uploads_dir');
        $before = scandir($uploadsDir);

        $notAnImage = tempnam(sys_get_temp_dir(), 'not-an-image') . '.jpg';
        file_put_contents($notAnImage, 'this is plain text, not a photo');
        $client->request(
            'POST',
            '/meal/clarify',
            ['_token' => (string) $crawler->filter('form[action="/meal/clarify"] input[name="_token"]')->attr('value')],
            ['extra_photo' => new UploadedFile($notAnImage, 'label.jpg', 'image/jpeg', null, true)],
        );
        $this->assertResponseRedirects('/meal/clarify');
        $html = $client->followRedirect()->html();

        // The user is told what is actually wrong, not that HEIC conversion failed.
        self::assertStringContainsString('Please upload a valid image', $html);
        self::assertStringNotContainsString("couldn't process that image format", $html);
        self::assertSame($before, scandir($uploadsDir), 'nothing was staged');
    }

    public function testCorrectedIngredientsAreBoundedBeforeTheyReachThePrompt(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $client->loginUser($this->seedUser($em, 'bounded@test.local'));

        $this->submitLog($client, describe: 'a bowl of soup');
        $crawler = $client->followRedirect();

        $token = (string) $crawler->filter('form[action="/meal/clarify"] input[name="_token"]')->attr('value');
        $client->request('POST', '/meal/clarify', [
            '_token'     => $token,
            'ing_name'   => [str_repeat('n', 300)],
            'ing_amount' => [str_repeat('7', 80)],
            'ing_unit'   => [str_repeat('u', 80)],
        ]);
        $this->assertResponseRedirects('/meal/clarify');

        $prompt = (string) FakeAnthropicResponder::lastAnalysisPrompt();
        self::assertStringContainsString(str_repeat('n', 200), $prompt);
        self::assertStringNotContainsString(str_repeat('n', 201), $prompt);
        self::assertStringContainsString(str_repeat('7', 50) . ' ' . str_repeat('u', 50) . ' ', $prompt);
        self::assertStringNotContainsString(str_repeat('7', 51), $prompt);
    }

    public function testSavingKeepsTheIngredientEditsWithoutAReanalysis(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $client->loginUser($this->seedUser($em, 'edits@test.local'));

        $this->submitLog($client, describe: 'fried chicken with rice');
        $crawler = $client->followRedirect();

        $token = (string) $crawler->filter('form[action="/meal/clarify"] input[name="_token"]')->attr('value');
        $client->request('POST', '/meal/clarify', [
            '_token'     => $token,
            'favorite'   => '1',
            'portions'   => '1',
            'ing_name'   => ['grilled chicken breast', 'brown rice'],
            'ing_amount' => ['150', '1'],
            'ing_unit'   => ['g', 'cup'],
        ]);
        // Per-request counter (the kernel reboots between requests): the save itself made no call.
        self::assertSame(0, static::getContainer()->get('test.anthropic_http_client')->getRequestsCount());
        $client->followRedirect();

        $edited = [
            ['name' => 'grilled chicken breast', 'amount' => '150', 'unit' => 'g'],
            ['name' => 'brown rice', 'amount' => '1', 'unit' => 'cup'],
        ];
        $em->clear();
        self::assertSame($edited, $em->getRepository(MealEntry::class)->findOneBy([])->getIngredients());
        self::assertSame($edited, $em->getRepository(Favorite::class)->findOneBy([])->getIngredients());
    }

    public function testDescribedMealLogsWithoutAPhoto(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $client->loginUser($this->seedUser($em, 'nophoto@test.local'));

        $crawler = $this->submitLog($client, describe: 'chicken caesar salad, large, no croutons');
        $this->assertResponseRedirects('/meal/clarify');
        $crawler = $client->followRedirect();

        // The review shows the user's own words where the photo would be.
        self::assertStringContainsString('You described', $crawler->html());
        self::assertStringContainsString('chicken caesar salad, large, no croutons', $crawler->html());

        $client->submit($crawler->selectButton('Save to log')->form());
        $client->followRedirect();

        $em->clear();
        $meal = $em->getRepository(MealEntry::class)->findOneBy([]);
        self::assertNotNull($meal, 'the described meal saved');
        self::assertNull($meal->getImagePath());

        // Its detail page renders fine without a photo.
        $client->request('GET', '/meal/' . $meal->getId());
        $this->assertResponseIsSuccessful();
    }

    public function testDescriptionSubmittedWithAPhotoReachesTheAnalysis(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $client->loginUser($this->seedUser($em, 'both@test.local'));

        // The failure trigger sits in the DESCRIPTION while a photo is present —
        // the analysis only fails if the description was folded into the prompt.
        $this->submitLog(
            $client,
            files: [$this->makeJpeg(60)],
            describe: \App\Tests\Http\FakeAnthropicResponder::FAIL_ALWAYS,
        );

        $this->assertResponseRedirects('/meal/log', message: 'analysis failed → parked, not reviewed');
        $crawler = $client->followRedirect();
        self::assertCount(1, $crawler->filter('#retry-form'), 'the description reached the billable prompt');
    }

    public function testReviewShowsBothPhotoAndDescriptionWhenBothSubmitted(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $client->loginUser($this->seedUser($em, 'bothshown@test.local'));

        $this->submitLog(
            $client,
            files: [$this->makeJpeg(70)],
            describe: 'with extra cheese and a side of pickles',
        );

        $this->assertResponseRedirects('/meal/clarify');
        $crawler = $client->followRedirect();

        self::assertStringContainsString('You described', $crawler->html());
        self::assertStringContainsString('with extra cheese and a side of pickles', $crawler->html());
        self::assertCount(1, $crawler->filter('.col-md-4 .card img'), 'the photo card renders too');
    }

    public function testNeitherPhotoNorTextIsRejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $client->loginUser($this->seedUser($em, 'neither@test.local'));

        $crawler = $this->submitLog($client);

        self::assertStringContainsString('Add a photo or describe your meal', $crawler->html());
        $em->clear();
        self::assertNull($em->getRepository(MealEntry::class)->findOneBy([]), 'nothing was analyzed or saved');
    }

    public function testFirstMealShowsTheSuccessMomentExactlyOnce(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $client->loginUser($this->seedUser($em, 'firstmeal@test.local'));

        // First save (fresh user, onboarding active) → the celebratory callout.
        $this->submitLog($client, describe: 'a bowl of oatmeal with blueberries');
        $crawler = $client->followRedirect();
        $client->submit($crawler->selectButton('Save to log')->form());
        $crawler = $client->followRedirect();
        self::assertStringContainsString("That's your first meal logged", $crawler->html());

        // Second save → no callout (the onboarding meal step is already stamped).
        $this->submitLog($client, describe: 'a turkey sandwich');
        $crawler = $client->followRedirect();
        $client->submit($crawler->selectButton('Save to log')->form());
        $crawler = $client->followRedirect();
        self::assertStringNotContainsString("That's your first meal logged", $crawler->html());
    }

    public function testBackdatedFirstMealGetsDateNeutralCallout(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $client->loginUser($this->seedUser($em, 'backdated@test.local'));

        // Backdate on the review screen (the EXIF-default editing path): the
        // celebration still fires, but must not promise a score for TODAY —
        // a backdated meal contributes nothing to today's dashboard.
        $this->submitLog($client, describe: 'leftover lasagna from the weekend');
        $crawler = $client->followRedirect();
        $form = $crawler->selectButton('Save to log')->form();
        $form['logged_at'] = (new \DateTimeImmutable('-3 days'))->format('Y-m-d\TH:i');
        $client->submit($form);
        $crawler = $client->followRedirect();

        self::assertStringContainsString("That's your first meal logged", $crawler->html());
        self::assertStringNotContainsString("See today's score", $crawler->html());
        self::assertStringContainsString('Go to the dashboard', $crawler->html());
    }

    public function testFreeUserSeesTheAllowanceCounterAndPaidUserDoesNot(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $free = $this->seedUser($em, 'freetier@test.local');
        $client->loginUser($free);
        $crawler = $client->request('GET', '/meal/log');
        self::assertStringContainsString('10 of 10 free AI analyses left this month', $crawler->html());

        $paid = $this->seedUser($em, 'paid@test.local');
        $paid->setCompTier(SubscriptionTier::LoopPlus);
        $em->flush();
        $client->loginUser($paid);
        $crawler = $client->request('GET', '/meal/log');
        self::assertStringNotContainsString('free AI analyses left', $crawler->html());
    }

    /**
     * Submits the log form the way the browser would: regular fields (+ CSRF
     * from the rendered form) as params, photos as files. The multiple-file
     * field needs a manual request — the crawler's Form API only fills the one
     * empty input.
     *
     * @param list<string> $files
     */
    private function submitLog(KernelBrowser $client, array $files = [], string $describe = ''): Crawler
    {
        $crawler = $client->request('GET', '/meal/log');
        $token = (string) $crawler->filter('#meal-form input[name="meal_entry_form[_token]"]')->attr('value');

        return $client->request(
            'POST',
            '/meal/log',
            ['meal_entry_form' => [
                'mealType'    => 'lunch',
                'description' => $describe,
                'notes'       => '',
                '_token'      => $token,
            ]],
            ['meal_entry_form' => [
                'image' => array_map(
                    static fn(string $p): UploadedFile => new UploadedFile($p, basename($p), 'image/jpeg', null, true),
                    $files,
                ),
            ]],
        );
    }

    private function makeJpeg(int $shade): string
    {
        $path = tempnam(sys_get_temp_dir(), 'paths-meal') . '.jpg';
        $im = imagecreatetruecolor(24, 24);
        imagefilledrectangle($im, 0, 0, 23, 23, (int) imagecolorallocate($im, $shade, 120, 40));
        imagejpeg($im, $path);

        return $path;
    }

    private function seedUser(EntityManagerInterface $em, string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Paths Tester');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable('-1 day'));
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
