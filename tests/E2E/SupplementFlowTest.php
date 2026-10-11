<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\Supplement;
use Facebook\WebDriver\WebDriverBy;

/**
 * Supplement label scan → confirm → save (Claude mocked to return a multivitamin).
 * Exercises the supplement branch of the AI mock and the confirm-form persistence.
 */
final class SupplementFlowTest extends E2ETestCase
{
    public function testScanToConfirmToSave(): void
    {
        $image = $this->makeJpeg();

        $client = self::createPantherClient();
        $this->login($client);

        $client->request('GET', '/supplements/scan');
        $client->findElement(WebDriverBy::cssSelector('input[type="file"]'))->sendKeys($image);
        $client->findElement(WebDriverBy::cssSelector('button[type="submit"]'))->click();

        // Confirm page is prefilled from the mocked analysis.
        $client->waitFor('input[name="name"]', 10);
        self::assertStringContainsString('/supplements/confirm', $client->getCurrentURL());

        $name = $client->findElement(WebDriverBy::name('name'));
        self::assertSame('Test Multivitamin', $name->getAttribute('value'));

        $client->findElement(WebDriverBy::cssSelector('button[type="submit"]'))->click();

        // Redirects to the supplements list; the supplement persisted.
        $client->wait(10)->until(
            static fn ($c) => str_contains($c->getCurrentURL(), '/supplements')
                && !str_contains($c->getCurrentURL(), '/confirm') ? true : null
        );

        $this->em->clear();
        $supplements = $this->em->getRepository(Supplement::class)->findAll();
        self::assertCount(1, $supplements);
        self::assertSame('Test Multivitamin', $supplements[0]->getName());
    }

    private function makeJpeg(): string
    {
        $path = sys_get_temp_dir() . '/e2e-supp-' . uniqid() . '.jpg';
        $im = imagecreatetruecolor(64, 64);
        imagefill($im, 0, 0, imagecolorallocate($im, 90, 140, 180));
        imagejpeg($im, $path);

        return $path;
    }
}
