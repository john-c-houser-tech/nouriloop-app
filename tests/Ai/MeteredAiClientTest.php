<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\AiClient;
use App\Ai\AiFeature;
use App\Ai\AiResult;
use App\Ai\MeteredAiClient;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class MeteredAiClientTest extends TestCase
{
    private const TURN = [['role' => 'user', 'content' => 'q']];

    public function testACallThatComesBackIsRecordedWithItsCost(): void
    {
        $inner = $this->createStub(AiClient::class);
        $inner->method('complete')->willReturn(new AiResult('ok', 1000, 200, 'anthropic', 'claude-sonnet-4-6'));

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(42);

        $conn = $this->createMock(Connection::class);
        $conn->expects(self::once())->method('insert')->with('ai_call', self::callback(
            static function (array $row): bool {
                self::assertSame(42, $row['user_id']);
                self::assertSame('goal_checkin', $row['feature']);
                self::assertSame('anthropic', $row['provider']);
                self::assertSame('claude-sonnet-4-6', $row['model']);
                self::assertSame(1000, $row['input_tokens']);
                self::assertSame(200, $row['output_tokens']);
                self::assertSame(1000 * 3 + 200 * 15, $row['cost_micros']);
                self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $row['occurred_at']);

                return true;
            },
        ));

        $result = (new MeteredAiClient($inner, $conn, new NullLogger()))
            ->complete(AiFeature::GoalCheckin, $user, 's', self::TURN);

        self::assertSame('ok', $result->text);
    }

    public function testAnImageCallWithAnUnpricedModelIsRecordedWithoutACost(): void
    {
        $inner = $this->createStub(AiClient::class);
        $inner->method('completeWithImages')->willReturn(new AiResult('{}', 900, 100, '127.0.0.1:11434', 'qwen2.5vl'));

        $conn = $this->createMock(Connection::class);
        $conn->expects(self::once())->method('insert')->with('ai_call', self::callback(
            static fn(array $row): bool => $row['user_id'] === null
                && $row['feature'] === 'meal_analysis'
                && $row['provider'] === '127.0.0.1:11434'
                && $row['cost_micros'] === null,
        ));

        (new MeteredAiClient($inner, $conn, new NullLogger()))
            ->completeWithImages(AiFeature::MealAnalysis, null, 'p', [], 64);
    }

    public function testAnAnthropicModelIdServedByAnotherProviderStaysUnpriced(): void
    {
        $inner = $this->createStub(AiClient::class);
        $inner->method('complete')->willReturn(new AiResult('ok', 1000, 200, 'openrouter.ai', 'claude-sonnet-4-6'));

        $conn = $this->createMock(Connection::class);
        $conn->expects(self::once())->method('insert')->with('ai_call', self::callback(
            static fn(array $row): bool => $row['provider'] === 'openrouter.ai' && $row['cost_micros'] === null,
        ));

        (new MeteredAiClient($inner, $conn, new NullLogger()))->complete(AiFeature::Predict, null, 's', self::TURN);
    }

    public function testARecordingFailureIsLoggedAndTheAnswerStillReturned(): void
    {
        $inner = $this->createStub(AiClient::class);
        $inner->method('complete')->willReturn(new AiResult('the answer', 1, 1, 'anthropic', 'm'));

        $conn = $this->createStub(Connection::class);
        $conn->method('insert')->willThrowException(new \RuntimeException('table missing'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('Could not record AI call cost');

        $result = (new MeteredAiClient($inner, $conn, $logger))->complete(AiFeature::Predict, null, 's', self::TURN);

        self::assertSame('the answer', $result->text);
    }

    public function testACallThatThrowsRecordsNothing(): void
    {
        $inner = $this->createStub(AiClient::class);
        $inner->method('complete')->willThrowException(new \RuntimeException('upstream 500'));

        $conn = $this->createMock(Connection::class);
        $conn->expects(self::never())->method('insert');

        $this->expectExceptionMessage('upstream 500');
        (new MeteredAiClient($inner, $conn, new NullLogger()))->complete(AiFeature::GoalSetup, null, 's', self::TURN);
    }
}
