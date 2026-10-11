<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\TonePromptBuilder;
use PHPUnit\Framework\TestCase;

final class TonePromptBuilderTest extends TestCase
{
    private TonePromptBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new TonePromptBuilder();
    }

    public function testDefaultsProduceTheNeutralVoice(): void
    {
        $prompt = $this->builder->build(3, 3, 3);

        self::assertStringContainsString('naturally conversational amount of humor', $prompt);
        self::assertStringContainsString('friendly and approachable', $prompt);
        self::assertStringContainsString('general adult reader', $prompt);
    }

    public function testExtremesSelectTheExtremePhrases(): void
    {
        $hot = $this->builder->build(5, 5, 5);
        self::assertStringContainsString('improv comedian', $hot);
        self::assertStringContainsString('doting grandparent', $hot);
        self::assertStringContainsString('expert-level', $hot);

        $cold = $this->builder->build(1, 1, 1);
        self::assertStringContainsString('dry and understated', $cold);
        self::assertStringContainsString('clinical and neutral', $cold);
        self::assertStringContainsString('no background at all', $cold);
    }

    public function testGuardrailIsAlwaysPresent(): void
    {
        foreach ([[1, 1, 1], [3, 3, 3], [5, 5, 5], [2, 4, 1]] as [$h, $w, $e]) {
            $prompt = $this->builder->build($h, $w, $e);
            self::assertStringContainsString('writing style only', $prompt);
            self::assertStringContainsString('not medical advice', $prompt);
            self::assertStringContainsString('no Markdown', $prompt);
        }
    }

    public function testOutOfRangeLevelsClamp(): void
    {
        // Must not throw on a missing ramp index.
        self::assertStringContainsString('improv comedian', $this->builder->build(99, 3, 3));
        self::assertStringContainsString('dry and understated', $this->builder->build(-1, 3, 3));
    }

    public function testForUserReadsTheUserDials(): void
    {
        $user = (new User())->setToneHumor(5)->setToneWarmth(1)->setToneEducation(5);

        $prompt = $this->builder->forUser($user);
        self::assertStringContainsString('improv comedian', $prompt);   // humor 5
        self::assertStringContainsString('clinical and neutral', $prompt); // warmth 1
        self::assertStringContainsString('expert-level', $prompt);        // education 5
    }

    public function testSignatureReflectsTheDials(): void
    {
        $a = (new User())->setToneHumor(1)->setToneWarmth(2)->setToneEducation(3);
        $b = (new User())->setToneHumor(5)->setToneWarmth(2)->setToneEducation(3);

        self::assertSame('tone-1-2-3', $this->builder->signature($a));
        self::assertNotSame($this->builder->signature($a), $this->builder->signature($b));
    }
}
