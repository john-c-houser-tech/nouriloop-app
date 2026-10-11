<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Enum\AnalysisKind;
use App\Service\ClaudeClient;
use App\Service\SupplementAnalysisService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SupplementAnalysisServiceTest extends TestCase
{
    use BuildsAllowance;

    private string $imagePath;

    protected function setUp(): void
    {
        $this->imagePath = tempnam(sys_get_temp_dir(), 'supp') . '.jpg';
        file_put_contents($this->imagePath, 'fake-jpeg-bytes');
    }

    protected function tearDown(): void
    {
        @unlink($this->imagePath);
    }

    public function testReadsTheLabelIntoAppNutrientKeys(): void
    {
        $result = $this->analyzeWith([
            'name' => '  Vitamin D3  ',
            'serving_size' => '1 softgel',
            'confidence' => 0.95,
            'vitamin_d' => 50,
            'saturated_fat' => '0.5',
            'calcium' => null,
            'zinc' => 'trace',
        ]);

        self::assertSame('Vitamin D3', $result->name);
        self::assertSame('1 softgel', $result->servingSize);
        self::assertSame(0.95, $result->confidence);
        // snake_case → app keys; nulls and non-numeric values are left out.
        self::assertSame(['saturatedFat' => 0.5, 'vitaminD' => 50.0], $result->nutrients);
    }

    public function testBlankNameFallsBackToSupplement(): void
    {
        self::assertSame('Supplement', $this->analyzeWith(['name' => '   '])->name);
    }

    public function testBooksTheScanOnTheLedgerWithItsTokens(): void
    {
        $this->analyzeWith(['name' => 'Fish oil'], usage: ['input_tokens' => 1200, 'output_tokens' => 300]);

        self::assertCount(1, $this->booked);
        self::assertSame(AnalysisKind::SupplementScan, $this->booked[0]->getKind());
        self::assertSame(1500, $this->booked[0]->getTokens());
    }

    public function testInvalidJsonBooksNothing(): void
    {
        $http = new MockHttpClient(new MockResponse((string) json_encode([
            'content' => [['type' => 'text', 'text' => 'Sorry, the label is blurry.']],
        ])));

        try {
            $this->service($http)->analyzeImages(new User(), [$this->imagePath]);
            self::fail('Expected an invalid-JSON exception.');
        } catch (\RuntimeException) {
        }

        self::assertSame([], $this->booked);
    }

    public function testMissingPhotosThrowBeforeAnyCall(): void
    {
        $http = new MockHttpClient(function (): MockResponse {
            self::fail('No request may be sent.');
        });

        $this->expectException(\RuntimeException::class);
        $this->service($http)->analyzeImages(new User(), ['/no/such/label.jpg']);
    }

    /**
     * @param array<string, mixed> $reply
     * @param array<string, int>   $usage
     */
    private function analyzeWith(array $reply, array $usage = []): \App\Dto\SupplementAnalysis
    {
        $http = new MockHttpClient(new MockResponse((string) json_encode([
            'content' => [['type' => 'text', 'text' => json_encode($reply)]],
            'usage' => $usage,
        ])));

        return $this->service($http)->analyzeImages(new User(), [$this->imagePath]);
    }

    private function service(MockHttpClient $http): SupplementAnalysisService
    {
        return new SupplementAnalysisService(new ClaudeClient($http, 'test-key'), $this->allowance());
    }
}
