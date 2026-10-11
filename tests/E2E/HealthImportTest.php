<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\HealthMetric;
use Facebook\WebDriver\WebDriverBy;

/**
 * Health-metric CSV import (upload-only web path). Format is auto-detected by
 * extension; rows land in health_metric (dedup via uq_health_metric).
 */
final class HealthImportTest extends E2ETestCase
{
    public function testCsvImport(): void
    {
        $csv = $this->makeCsv();

        $client = self::createPantherClient();
        $this->login($client);

        $client->request('GET', '/health-metrics/import');
        $client->findElement(WebDriverBy::id('health_file'))->sendKeys($csv);
        // Plain multipart form (its hidden _token rides along) — submit it directly.
        $client->executeScript("document.getElementById('health_file').closest('form').submit()");

        // Redirects to the metrics list on success.
        $client->wait(10)->until(
            static fn ($c) => str_contains($c->getCurrentURL(), '/health-metrics')
                && !str_contains($c->getCurrentURL(), '/import') ? true : null
        );

        $this->em->clear();
        $metrics = $this->em->getRepository(HealthMetric::class)->findAll();
        self::assertCount(2, $metrics);
    }

    private function makeCsv(): string
    {
        $path = sys_get_temp_dir() . '/e2e-health-' . uniqid() . '.csv';
        file_put_contents(
            $path,
            "date,type,value\n2026-06-15,weight,80\n2026-06-10,heart rate,60\n"
        );

        return $path;
    }
}
