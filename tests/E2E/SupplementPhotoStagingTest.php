<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\Supplement;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A supplement scan's photo is staged with every other draft's, in the
 * directory app:cleanup-meal-uploads sweeps, so an abandoned scan leaves
 * nothing behind for good; saving moves the thumbnail to uploads/supplements.
 */
final class SupplementPhotoStagingTest extends WebTestCase
{
    use DbResetTrait;

    /** @var list<string> */
    private array $created = [];

    protected function tearDown(): void
    {
        foreach ($this->created as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function testAScanStagesItsPhotoWhereTheSweepReachesAndSavingMovesIt(): void
    {
        $client = $this->signedIn();
        $uploadsDir = (string) static::getContainer()->getParameter('uploads_dir');
        $supplementsDir = (string) static::getContainer()->getParameter('supplements_dir');

        $before = glob($uploadsDir . '/supp-*') ?: [];
        $this->submitScan($client);
        self::assertResponseRedirects('/supplements/confirm');
        $crawler = $client->followRedirect();

        // Staged in the swept directory, not in uploads/supplements.
        $new = array_values(array_diff(glob($uploadsDir . '/supp-*') ?: [], $before));
        self::assertCount(1, $new, 'one staged label photo in the swept directory');
        $staged = $new[0];
        $this->created[] = $staged;
        $src = basename($staged);

        $client->submit($crawler->selectButton('Save supplement')->form(['name' => 'Vitamin D3']));
        self::assertResponseRedirects('/supplements');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $supplement = $em->getRepository(Supplement::class)->findOneBy([]);
        self::assertNotNull($supplement);
        self::assertSame('uploads/supplements/' . basename($src), $supplement->getImagePath());
        $kept = $supplementsDir . '/' . basename($src);
        $this->created[] = $kept;
        self::assertFileExists($kept);
        self::assertFileDoesNotExist($staged, 'moved, not copied');
    }

    private function signedIn(): KernelBrowser
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('stager@test.local');
        $user->setDisplayName('Stager');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable('-1 day'));
        $user->setOnboardingDismissed(true);
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);

        return $client;
    }

    private function submitScan(KernelBrowser $client): void
    {
        $crawler = $client->request('GET', '/supplements/scan');
        $token = (string) $crawler->filter('input[name="supplement_photo_form[_token]"]')->attr('value');

        $photo = tempnam(sys_get_temp_dir(), 'supp-stage') . '.jpg';
        imagejpeg(imagecreatetruecolor(24, 24), $photo);

        $client->request(
            'POST',
            '/supplements/scan',
            ['supplement_photo_form' => ['_token' => $token]],
            ['supplement_photo_form' => [
                'images' => [new UploadedFile($photo, 'label.jpg', 'image/jpeg', null, true)],
            ]],
        );
    }
}
