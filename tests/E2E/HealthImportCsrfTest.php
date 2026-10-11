<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\HealthMetric;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The health-metrics import is a hand-rolled multipart form (not the Form
 * component), so it carries its own `health-import` CSRF token. In-process
 * `WebTestCase` against the real DB — the Panther `HealthImportTest` covers the
 * browser path.
 */
final class HealthImportCsrfTest extends WebTestCase
{
    use DbResetTrait;

    public function testImportWithoutTokenIsRejectedAndImportsNothing(): void
    {
        [$client, $em] = $this->signedInClient();

        $client->request('POST', '/health-metrics/import', [], ['health_file' => $this->csvUpload()]);

        self::assertResponseRedirects('/health-metrics/import');
        $crawler = $client->followRedirect();
        self::assertStringContainsString('Your session expired', $crawler->html());
        $em->clear();
        self::assertCount(0, $em->getRepository(HealthMetric::class)->findAll());
    }

    public function testImportWithForgedTokenIsRejected(): void
    {
        [$client, $em] = $this->signedInClient();

        $client->request(
            'POST',
            '/health-metrics/import',
            ['_token' => 'forged'],
            ['health_file' => $this->csvUpload()],
        );

        self::assertResponseRedirects('/health-metrics/import');
        $em->clear();
        self::assertCount(0, $em->getRepository(HealthMetric::class)->findAll());
    }

    public function testImportFormCarriesTheTokenAndImports(): void
    {
        [$client, $em] = $this->signedInClient();

        $crawler = $client->request('GET', '/health-metrics/import');
        $form = $crawler->filter('form[enctype="multipart/form-data"]')->form();
        $form['health_file']->upload($this->csvPath());
        $client->submit($form);

        self::assertResponseRedirects('/health-metrics');
        $em->clear();
        self::assertCount(2, $em->getRepository(HealthMetric::class)->findAll());
    }

    /** @return array{KernelBrowser, EntityManagerInterface} */
    private function signedInClient(): array
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('importer@test.local');
        $user->setDisplayName('Importer');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setTimezone('America/New_York');
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);

        return [$client, $em];
    }

    private function csvPath(): string
    {
        $path = sys_get_temp_dir() . '/csrf-health-' . uniqid() . '.csv';
        file_put_contents($path, "date,type,value\n2026-06-15,weight,80\n2026-06-10,heart rate,60\n");

        return $path;
    }

    private function csvUpload(): UploadedFile
    {
        return new UploadedFile($this->csvPath(), 'metrics.csv', 'text/csv', null, true);
    }
}
