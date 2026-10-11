<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\HealthMetric;
use Facebook\WebDriver\WebDriverBy;
use Symfony\Component\HttpClient\HttpClient;

/**
 * The only coverage of the bearer-token `^/api` firewall: create a token in the
 * UI (one-time plaintext reveal), then ingest a metric with it over the API.
 */
final class ApiTokenTest extends E2ETestCase
{
    public function testCreateTokenThenIngestViaApi(): void
    {
        $client = self::createPantherClient();
        $this->login($client);

        $client->request('GET', '/settings/api-tokens');
        $client->submitForm('Create', ['name' => 'My device']);

        // The plaintext token is shown exactly once after creation; wait for the
        // post-redirect reveal rather than racing it.
        $client->waitFor('#new-token', 10);
        $token = $client->findElement(WebDriverBy::id('new-token'))->getAttribute('value');
        self::assertStringStartsWith('nutri_', (string) $token);

        // Use the token against the stateless ingestion API (a non-browser client).
        $response = HttpClient::create()->request('POST', self::$baseUri . '/api/health-metrics', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'json' => [
                'metrics' => [
                    ['type' => 'weight', 'value' => 81.5, 'recordedAt' => '2026-06-15T08:00:00+00:00'],
                ],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $response->toArray()['imported']);

        $this->em->clear();
        $metrics = $this->em->getRepository(HealthMetric::class)->findAll();
        self::assertCount(1, $metrics);
        self::assertEqualsWithDelta(81.5, $metrics[0]->getValue(), 0.01);
    }

    public function testApiRejectsMissingToken(): void
    {
        // Establish the server (and self::$baseUri) without authenticating the API call.
        $client = self::createPantherClient();
        $client->request('GET', '/login');

        $response = HttpClient::create()->request('POST', self::$baseUri . '/api/health-metrics', [
            'json' => ['metrics' => []],
        ]);

        self::assertSame(401, $response->getStatusCode());
    }
}
