<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\MealEntry;
use App\Entity\User;
use App\Service\ExifTimestampService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A photo's EXIF time is the default "when eaten" (so older photos log onto their
 * own days), but a FUTURE EXIF time — camera clock set ahead — must fall back to
 * now; otherwise it sticks, since the save path rejects a future posted time and
 * keeps the default. The EXIF reader is stubbed (building an EXIF segment by hand
 * isn't worth it); the kernel is kept so the stub survives the requests.
 */
final class MealExifDefaultTest extends WebTestCase
{
    use DbResetTrait;

    public function testFutureExifTimeFallsBackToNow(): void
    {
        $client = $this->clientWithExif(new \DateTimeImmutable('+3 days'));

        $meal = $this->logPhotoMealAndSave($client);

        self::assertLessThanOrEqual(
            (new \DateTimeImmutable('now', new \DateTimeZone('America/New_York')))->format('Y-m-d H:i'),
            $meal->getLoggedAt()->format('Y-m-d H:i'),
        );
    }

    public function testPastExifTimeIsStillTheDefault(): void
    {
        $exif = new \DateTimeImmutable('2026-06-01 12:34:00');
        $client = $this->clientWithExif($exif);

        $meal = $this->logPhotoMealAndSave($client);

        self::assertSame('2026-06-01 12:34', $meal->getLoggedAt()->format('Y-m-d H:i'));
    }

    private function clientWithExif(\DateTimeImmutable $exif): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set(ExifTimestampService::class, new class ($exif) extends ExifTimestampService {
            public function __construct(private readonly \DateTimeImmutable $fixed)
            {
            }

            public function extractTimestamp(string $imagePath): ?\DateTimeImmutable
            {
                return $this->fixed;
            }
        });

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('exif@test.local');
        $user->setDisplayName('Exif');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setTimezone('America/New_York');
        $user->setEmailVerifiedAt(new \DateTimeImmutable('-1 day'));
        $user->setOnboardingDismissed(true);
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);

        return $client;
    }

    private function logPhotoMealAndSave(KernelBrowser $client): MealEntry
    {
        $crawler = $client->request('GET', '/meal/log');
        $token = (string) $crawler->filter('#meal-form input[name="meal_entry_form[_token]"]')->attr('value');
        $photo = tempnam(sys_get_temp_dir(), 'exif') . '.jpg';
        $im = imagecreatetruecolor(24, 24);
        imagejpeg($im, $photo);

        $client->request(
            'POST',
            '/meal/log',
            ['meal_entry_form' => ['mealType' => 'lunch', 'description' => '', 'notes' => '', '_token' => $token]],
            ['meal_entry_form' => ['image' => [new UploadedFile($photo, 'meal.jpg', 'image/jpeg', null, true)]]],
        );
        self::assertResponseRedirects('/meal/clarify');

        // Save exactly as the review screen presents it (its prefilled time included).
        $crawler = $client->request('GET', '/meal/clarify');
        $form = $crawler->filter('form[action="/meal/clarify"]');
        $client->request('POST', '/meal/clarify', [
            '_token'    => (string) $form->filter('input[name="_token"]')->attr('value'),
            'logged_at' => (string) $form->filter('input[name="logged_at"]')->attr('value'),
            'portions'  => '1',
            'accept'    => '1',
        ]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $meal = $em->getRepository(MealEntry::class)->findOneBy([]);
        self::assertNotNull($meal);

        return $meal;
    }
}
