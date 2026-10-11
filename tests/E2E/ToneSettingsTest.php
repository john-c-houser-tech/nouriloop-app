<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Settings ▸ Voice & tone: the page renders the three dials and saving persists
 * them to the user. In-process WebTestCase (the form is a plain CSRF-protected POST,
 * no JS needed to submit), kept under tests/E2E so it runs in the DB-having e2e job.
 */
final class ToneSettingsTest extends WebTestCase
{
    use DbResetTrait;

    public function testPageRendersTheThreeDialsAtTheirCurrentValues(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'tone@test.local');
        $user->setToneHumor(4);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/settings/tone');

        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('input[type="range"][data-tone-slider]'));
        self::assertSame('4', $crawler->filter('#tone_humor')->attr('value'));
        // The humorous end-labels are present.
        self::assertStringContainsString('Robin Williams on crack', $crawler->filter('body')->text());
    }

    public function testSavingPersistsTheDials(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'tone@test.local');

        $client->loginUser($user);
        $crawler = $client->request('GET', '/settings/tone');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/settings/tone', [
            '_token'         => $token,
            'tone_humor'     => '5',
            'tone_warmth'    => '1',
            'tone_education' => '4',
        ]);

        self::assertResponseRedirects('/settings/tone');

        $em->clear();
        $saved = $em->getRepository(User::class)->findOneBy(['email' => 'tone@test.local']);
        self::assertSame(5, $saved->getToneHumor());
        self::assertSame(1, $saved->getToneWarmth());
        self::assertSame(4, $saved->getToneEducation());
    }

    public function testOutOfRangeValuesAreClamped(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'tone@test.local');

        $client->loginUser($user);
        $crawler = $client->request('GET', '/settings/tone');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/settings/tone', [
            '_token'         => $token,
            'tone_humor'     => '99',
            'tone_warmth'    => '-3',
            'tone_education' => '3',
        ]);

        $em->clear();
        $saved = $em->getRepository(User::class)->findOneBy(['email' => 'tone@test.local']);
        self::assertSame(5, $saved->getToneHumor());
        self::assertSame(1, $saved->getToneWarmth());
    }

    private function seedUser(EntityManagerInterface $em, string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Tone Tester');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setTimezone('America/New_York');
        // Verified so the unverified-email banner (its form/_token would be picked up
        // by naive crawler selectors) stays out of these flows.
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
