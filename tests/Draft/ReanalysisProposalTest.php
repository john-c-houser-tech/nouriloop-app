<?php

declare(strict_types=1);

namespace App\Tests\Draft;

use App\Draft\ReanalysisProposal;
use App\Dto\AnalysisResult;
use App\Entity\NutritionData;
use PHPUnit\Framework\TestCase;

final class ReanalysisProposalTest extends TestCase
{
    /** @var list<string> */
    private array $photos = [];

    protected function setUp(): void
    {
        $this->photos = [tempnam(sys_get_temp_dir(), 'reanalysis'), tempnam(sys_get_temp_dir(), 'reanalysis')];
    }

    protected function tearDown(): void
    {
        foreach ($this->photos as $path) {
            @unlink($path);
        }
    }

    public function testAProposalRoundTripsWithoutTheUnreadTokensKey(): void
    {
        $proposal = $this->proposal(replacePhoto: true);

        self::assertSame(
            ['meal_id', 'description', 'new_paths', 'replace_photo', 'ai_description', 'questions', 'ingredients',
                'nutrition'],
            array_keys($proposal->toArray()),
        );
        self::assertEquals($proposal, ReanalysisProposal::fromArray($proposal->toArray()));
    }

    public function testAProposalStoredWithTheOldTokensKeyStillLoads(): void
    {
        $data = $this->proposal(replacePhoto: false)->toArray() + ['tokens' => 1500];

        self::assertNotNull(ReanalysisProposal::fromArray($data));
    }

    public function testItBelongsToTheOneMealItWasMadeFor(): void
    {
        $proposal = $this->proposal(replacePhoto: false);

        self::assertTrue($proposal->isFor(42));
        self::assertFalse($proposal->isFor(43));
    }

    public function testOnlyAReplacingProposalHandsTheMealAPhoto(): void
    {
        self::assertSame($this->photos[0], $this->proposal(replacePhoto: true)->appliedPhoto());
        self::assertNull($this->proposal(replacePhoto: false)->appliedPhoto());
    }

    public function testNoPhotosMeansNothingToReplaceWith(): void
    {
        $proposal = ReanalysisProposal::fromAnalysis(42, 'soup', [], true, $this->analysis());

        self::assertFalse($proposal->replacePhoto);
        self::assertNull($proposal->appliedPhoto());
    }

    public function testVanishedPhotosAreDroppedAndTakeTheReplacementWithThem(): void
    {
        $proposal = $this->proposal(replacePhoto: true);

        unlink($this->photos[1]);
        self::assertSame([$this->photos[0]], $proposal->withoutMissingPhotos()->newPaths);
        self::assertTrue($proposal->withoutMissingPhotos()->replacePhoto);

        unlink($this->photos[0]);
        $pruned = $proposal->withoutMissingPhotos();
        self::assertSame([], $pruned->newPaths);
        self::assertFalse($pruned->replacePhoto, 'nothing left to replace the meal photo with');
    }

    public function testAnEntryWithoutAMealIdIsNotAProposal(): void
    {
        self::assertNull(ReanalysisProposal::fromArray(['nutrition' => []]));
    }

    private function proposal(bool $replacePhoto): ReanalysisProposal
    {
        return ReanalysisProposal::fromAnalysis(42, 'Tomato soup', $this->photos, $replacePhoto, $this->analysis());
    }

    private function analysis(): AnalysisResult
    {
        return new AnalysisResult(
            NutritionData::fromArray(['calories' => 250.0, 'confidenceScore' => 0.85]),
            0.85,
            'A bowl of tomato soup',
            [],
            [['name' => 'tomato', 'amount' => '3', 'unit' => '']],
            1200,
            300,
        );
    }
}
