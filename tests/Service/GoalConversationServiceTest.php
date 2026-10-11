<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\GoalConversationService;
use PHPUnit\Framework\TestCase;

final class GoalConversationServiceTest extends TestCase
{
    public function testParsesFencedProposalBlock(): void
    {
        $reply = <<<'TXT'
        Great — based on your logs you're averaging ~45 g/day, so let's aim a bit lower.

        ```goal-proposal
        {"name": "Cut back on sugar", "metric": "sugar", "direction": "at_most",
         "aggregation": "daily_average", "targetValue": 30, "evaluationWindowDays": 7,
         "frequency": "weekly", "dayOfWeek": 7, "hour": 18, "minute": 0,
         "outputFormat": "summary", "deliveryChannel": "email"}
        ```
        TXT;

        $proposal = GoalConversationService::parseProposal($reply);

        self::assertNotNull($proposal);
        self::assertSame('sugar', $proposal['metric']);
        self::assertSame(30, $proposal['targetValue']);
        self::assertSame('weekly', $proposal['frequency']);
    }

    public function testNoProposalWhenBlockAbsent(): void
    {
        self::assertNull(GoalConversationService::parseProposal('Just a question — how active are you day to day?'));
    }

    public function testStripsProposalBlockFromVisibleText(): void
    {
        $reply = "Here's what I suggest:\n```goal-proposal\n{\"metric\":\"fiber\"}\n```";

        $visible = GoalConversationService::stripProposalBlock($reply);

        self::assertSame("Here's what I suggest:", $visible);
        self::assertStringNotContainsString('goal-proposal', $visible);
    }
}
