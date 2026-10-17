<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Enum\AllowanceKind;
use App\Enum\SubscriptionTier;
use App\Exception\PhotoLimitExceededException;
use App\Service\AnalysisAllowance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Who has a monthly allowance, how big, and where a user who has used it up is sent
 * (App Development Plan PR 7, ADR 0003).
 */
final class AnalysisAllowanceLimitTest extends TestCase
{
    use BuildsAllowance;

    /** @return iterable<string, array{SubscriptionTier, bool, ?AllowanceKind, ?int}> */
    public static function plans(): iterable
    {
        $free = AnalysisAllowance::FREE_MONTHLY_LIMIT;
        $included = AnalysisAllowance::INCLUDED_MONTHLY_LIMIT;

        yield 'Free' => [SubscriptionTier::Free, false, AllowanceKind::Free, $free];
        // The allowance is what Free is; their own key doesn't lift it.
        yield 'Free with a key' => [SubscriptionTier::Free, true, AllowanceKind::Free, $free];
        yield 'Loop before a key' => [SubscriptionTier::Loop, false, AllowanceKind::KeyPending, $free];
        yield 'Loop with a key' => [SubscriptionTier::Loop, true, null, null];
        yield 'Loop+' => [SubscriptionTier::LoopPlus, false, AllowanceKind::Included, $included];
        yield 'Loop+ with a key' => [SubscriptionTier::LoopPlus, true, null, null];
    }

    #[DataProvider('plans')]
    public function testEachPlansAllowance(
        SubscriptionTier $tier,
        bool $ownKey,
        ?AllowanceKind $kind,
        ?int $limit,
    ): void {
        $allowance = $this->allowance(used: 3, tier: $tier, ownKey: $ownKey);
        $user = $this->savedUser();

        self::assertSame($kind, $allowance->kind($user));
        self::assertSame($limit, $allowance->limit($user));
        self::assertSame($limit === null ? null : $limit - 3, $allowance->remaining($user));
    }

    public function testATopUpAddsToLoopPlusThisMonth(): void
    {
        $allowance = $this->allowance(
            used: AnalysisAllowance::INCLUDED_MONTHLY_LIMIT,
            tier: SubscriptionTier::LoopPlus,
            ownKey: false,
            toppedUp: AnalysisAllowance::TOP_UP_ANALYSES,
        );
        $user = $this->savedUser();

        self::assertSame(
            AnalysisAllowance::INCLUDED_MONTHLY_LIMIT + AnalysisAllowance::TOP_UP_ANALYSES,
            $allowance->limit($user),
        );
        self::assertSame(AnalysisAllowance::TOP_UP_ANALYSES, $allowance->remaining($user));
        self::assertTrue($allowance->canTopUp($user));
    }

    /** Only Loop+ on our key can buy one: Free upgrades, Loop and key holders use their key. */
    public function testOnlyLoopPlusOnOurKeyCanTopUp(): void
    {
        $user = $this->savedUser();
        self::assertFalse($this->allowance(tier: SubscriptionTier::Free, ownKey: false)->canTopUp($user));
        self::assertFalse($this->allowance(tier: SubscriptionTier::Loop, ownKey: false)->canTopUp($user));
        self::assertFalse($this->allowance(tier: SubscriptionTier::LoopPlus, ownKey: true)->canTopUp($user));
    }

    /** The counter warns at 80% used (decision Q3). */
    public function testTheCounterIsLowAtEightyPercent(): void
    {
        $user = $this->savedUser();
        $plus = fn(int $used) => $this->allowance(used: $used, tier: SubscriptionTier::LoopPlus, ownKey: false);

        self::assertFalse($plus(95)->status($user)?->isLow());
        self::assertTrue($plus(96)->status($user)?->isLow());
    }

    public function testASelfHostedCopyHasNoAllowance(): void
    {
        $allowance = $this->allowance(used: 500, tier: SubscriptionTier::LoopPlus, ownKey: false, hosting: 'self');

        self::assertNull($allowance->status($this->savedUser()));
    }

    /** @return iterable<string, array{SubscriptionTier, int, string, string}> */
    public static function exhausted(): iterable
    {
        yield 'Free goes to pricing' => [
            SubscriptionTier::Free, AnalysisAllowance::FREE_MONTHLY_LIMIT, 'meal.log.limit_reached', 'app_pricing',
        ];
        yield 'Loop goes to add a key' => [
            SubscriptionTier::Loop, AnalysisAllowance::FREE_MONTHLY_LIMIT,
            'meal.log.limit_reached_key_pending', 'app_settings_ai',
        ];
        yield 'Loop+ goes to add a key' => [
            SubscriptionTier::LoopPlus, AnalysisAllowance::INCLUDED_MONTHLY_LIMIT,
            'meal.log.limit_reached_included', 'app_allowance',
        ];
    }

    #[DataProvider('exhausted')]
    public function testAUsedUpAllowanceSaysWhereMoreComesFrom(
        SubscriptionTier $tier,
        int $limit,
        string $messageKey,
        string $route,
    ): void {
        $allowance = $this->allowance(used: $limit, tier: $tier, ownKey: false);

        try {
            $allowance->meter($this->savedUser(), null, null, fn() => self::fail('The analysis must not run.'));
            self::fail('Expected the allowance to refuse.');
        } catch (PhotoLimitExceededException $e) {
            self::assertSame($messageKey, $e->messageKey());
            self::assertSame(['limit' => $limit], $e->messageParameters());
            self::assertSame($route, $e->redirectRoute());
        }
    }

    private function savedUser(): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 42);

        return $user;
    }
}
