<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\AdminUserFilters;
use App\Entity\User;
use App\Enum\SubscriptionTier;
use App\Hosting\HostingMode;
use App\Repository\InvitationCodeRepository;
use App\Repository\MealEntryRepository;
use App\Repository\UserRepository;
use App\Service\AccountDeletionService;
use App\Service\InvitationCodeManager;
use App\Service\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/users')]
#[IsGranted('ROLE_ADMIN')]
class AdminUserController extends AbstractController
{
    private const PER_PAGE = 25;

    #[Route('', name: 'app_admin_users', methods: ['GET'])]
    public function index(
        Request $request,
        UserRepository $users,
        InvitationCodeRepository $codes,
        MealEntryRepository $meals,
        AccountDeletionService $accountDeletion,
        SubscriptionService $subscriptions,
        HostingMode $hosting,
    ): Response {
        $now = new \DateTimeImmutable();
        // "Active user" = logged in AND logged a meal within the last 7 days.
        $cutoff = $now->modify('-7 days');

        $q = trim((string) $request->query->get('q', ''));
        $tier = SubscriptionTier::tryFrom((string) $request->query->get('tier', ''));
        $activity = $this->whitelist($request->query->get('activity'), AdminUserFilters::ACTIVITIES);
        $status = $this->whitelist($request->query->get('status'), AdminUserFilters::STATUSES);

        $filters = new AdminUserFilters($now, $cutoff, $q !== '' ? $q : null, $tier, $activity, $status);

        $totalUsers = $users->countSearch($filters);
        $totalPages = max(1, (int) ceil($totalUsers / self::PER_PAGE));
        $page = max(1, min((int) $request->query->get('page', 1), $totalPages));
        $pageUsers = $users->searchPage($filters, $page, self::PER_PAGE);

        $recentMealUserIds = array_flip($meals->userIdsWithMealSince($cutoff));

        $activeUserIds = [];
        $pendingDeletionDates = [];
        $tierLabels = [];
        $tierTitles = [];
        $tierActions = [];
        foreach ($pageUsers as $user) {
            $lastLogin = $user->getLastLoginAt();
            if (
                $lastLogin !== null
                && $lastLogin >= $cutoff
                && isset($recentMealUserIds[$user->getId()])
            ) {
                $activeUserIds[$user->getId()] = true;
            }

            $purgeDate = $accountDeletion->scheduledPurgeDate($user);
            if ($purgeDate !== null) {
                $pendingDeletionDates[$user->getId()] = $purgeDate;
            }

            $effective = $subscriptions->effectiveTier($user);
            $floor = $subscriptions->paidTier($user);
            $tierLabels[$user->getId()] = $effective;
            $tierTitles[$user->getId()] = $this->tierTooltip($subscriptions, $user);
            // Drives the row's Upgrade/Downgrade menu items: labels of the one-step
            // targets (null = hidden), plus whether Downgrade is blocked by a paid
            // Stripe tier (shown disabled with an explanation, not silently hidden).
            $tierActions[$user->getId()] = [
                'up' => SubscriptionTier::fromRank($effective->rank() + 1)?->label(),
                'down' => $effective->rank() > $floor->rank()
                    ? SubscriptionTier::fromRank($effective->rank() - 1)?->label()
                    : null,
                'downBlocked' => $effective !== SubscriptionTier::Free && $effective->rank() <= $floor->rank(),
            ];
            if ($hosting->isSelfHosted()) {
                // A self-hosted copy has no tiers (everyone has every feature): no plan menu.
                $tierActions[$user->getId()] = ['up' => null, 'down' => null, 'downBlocked' => false];
            }
        }

        return $this->render('admin/users/index.html.twig', [
            'users' => $pageUsers,
            'codes' => $codes->findOutstanding(),
            // One source for the promised term — the mint checkbox, the code
            // badge and the mint flash all render it, so operator copy can't
            // drift from what grantBetaTesterComp actually grants.
            'betaCompLabel' => SubscriptionService::BETA_COMP_LABEL,
            'now' => $now,
            'activeUserIds' => $activeUserIds,
            'pendingDeletionDates' => $pendingDeletionDates,
            'tierLabels' => $tierLabels,
            'tierTitles' => $tierTitles,
            'tierActions' => $tierActions,
            'q' => $q,
            'tier' => $tier?->value,
            'activity' => $activity,
            'status' => $status,
            'page' => $page,
            'totalPages' => $totalPages,
            'totalUsers' => $totalUsers,
            'rangeStart' => $totalUsers === 0 ? 0 : ($page - 1) * self::PER_PAGE + 1,
            'rangeEnd' => min($page * self::PER_PAGE, $totalUsers),
        ]);
    }

    /** @param string[] $allowed */
    private function whitelist(mixed $value, array $allowed): ?string
    {
        $value = is_string($value) ? $value : '';

        return in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * A short explanation of *why* a user has their effective tier — surfaced as the
     * plan badge's tooltip so a comp or a lapsing grace period isn't invisible.
     */
    private function tierTooltip(SubscriptionService $subscriptions, User $user): string
    {
        $effective = $subscriptions->effectiveTier($user);
        $parts = [$effective->label()];

        if ($subscriptions->hasActiveComp($user) && $user->getCompTier() === $effective) {
            $expires = $user->getCompExpiresAt();
            $parts[] = $expires === null
                ? 'complimentary (permanent)'
                : 'complimentary, expires ' . $this->formatInstant($expires);
        } elseif ($effective !== SubscriptionTier::Free) {
            $status = (string) $user->getSubscriptionStatus();
            $periodEnd = $user->getSubscriptionPeriodEnd();
            if (in_array($status, SubscriptionService::GRACE_STATUSES, true) && $periodEnd !== null) {
                $parts[] = $status . ', access until ' . $this->formatInstant($periodEnd);
            } elseif ($status !== '') {
                $parts[] = $status;
            }
        }

        return implode(' · ', $parts);
    }

    /**
     * Format an absolute instant (comp expiry, subscription period end) in the
     * *viewing admin's* timezone — mirrors the `user_date` Twig filter
     * (App\Twig\TimeExtension) the rest of the page uses, so a tooltip built here can't
     * show a date a day off from the row's other dates for an admin outside the server
     * zone.
     */
    private function formatInstant(\DateTimeImmutable $instant): string
    {
        $viewer = $this->getUser();
        $tz = new \DateTimeZone($viewer instanceof User ? $viewer->getTimezone() : User::DEFAULT_TIMEZONE);

        return $instant->setTimezone($tz)->format('M j, Y');
    }

    #[Route('/invitations', name: 'app_admin_invitation_create', methods: ['POST'])]
    public function createInvitation(
        Request $request,
        InvitationCodeManager $manager,
    ): Response {
        if (!$this->isCsrfTokenValid('admin-invite-create', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_admin_users');
        }

        /** @var User $admin */
        $admin = $this->getUser();
        $betaTester = $request->request->getBoolean('beta_tester');
        $code = $manager->create($admin, $betaTester);
        $this->addFlash('success', sprintf(
            'Invitation code created: %s (valid for %d hours)%s.',
            $code->getCode(),
            InvitationCodeManager::TTL_HOURS,
            $betaTester ? sprintf(' — beta tester: %s free Loop+', SubscriptionService::BETA_COMP_LABEL) : '',
        ));

        return $this->redirectToRoute('app_admin_users');
    }

    #[Route(
        '/invitations/{id}/delete',
        name: 'app_admin_invitation_delete',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function deleteInvitation(
        int $id,
        Request $request,
        InvitationCodeRepository $codes,
        EntityManagerInterface $em,
    ): Response {
        $code = $codes->find($id);
        if ($code === null) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('admin-invite-delete-' . $id, (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_admin_users');
        }

        if ($code->isUsed()) {
            // Redeemed codes are historical (shown on the user's row); don't delete.
            $this->addFlash('error', 'That code has already been used and cannot be revoked.');

            return $this->redirectToRoute('app_admin_users');
        }

        $em->remove($code);
        $em->flush();
        $this->addFlash('success', 'Invitation code revoked.');

        return $this->redirectToRoute('app_admin_users');
    }

    #[Route('/{id}/toggle', name: 'app_admin_user_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(
        int $id,
        Request $request,
        UserRepository $users,
        EntityManagerInterface $em,
    ): Response {
        $user = $users->find($id);
        if ($user === null) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('admin-toggle-' . $id, (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_admin_users');
        }

        if ($user === $this->getUser()) {
            $this->addFlash('error', 'You cannot disable your own account.');

            return $this->redirectToRoute('app_admin_users');
        }

        $user->setActive(!$user->isActive());
        $em->flush();
        $this->addFlash('success', sprintf(
            '%s has been %s.',
            $user->getEmail(),
            $user->isActive() ? 'enabled' : 'disabled',
        ));

        return $this->redirectToRoute('app_admin_users');
    }

    /**
     * Upgrade one tier step (Free → Loop → Loop+) by granting a PERMANENT comp at
     * the target tier — the same billing-independent mechanism as beta
     * grandfathering, so Stripe is never touched and revoking is just Downgrade.
     * Self-service is allowed (unlike enable/disable) so an admin can raise their
     * own account for testing — the old AI Coach toggle's posture.
     */
    #[Route('/{id}/tier/up', name: 'app_admin_user_tier_up', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function tierUp(
        int $id,
        Request $request,
        UserRepository $users,
        EntityManagerInterface $em,
        SubscriptionService $subscriptions,
    ): Response {
        $user = $users->find($id);
        if ($user === null) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('admin-tier-up-' . $id, (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_admin_users');
        }

        $effective = $subscriptions->effectiveTier($user);
        $target = SubscriptionTier::fromRank($effective->rank() + 1);
        if ($target === null) {
            $this->addFlash('error', sprintf('%s is already on the highest tier.', $user->getEmail()));

            return $this->redirectToRoute('app_admin_users');
        }

        $subscriptions->grantComp($user, $target, null);
        $em->flush();
        $this->addFlash('success', sprintf(
            '%s upgraded to %s (complimentary, permanent — billing unchanged).',
            $user->getEmail(),
            $target->label(),
        ));

        return $this->redirectToRoute('app_admin_users');
    }

    /**
     * Downgrade one tier step by lowering/removing the comp. Bounded below by the
     * user's PAID tier (an active/in-grace Stripe subscription): pushing a paying
     * subscriber lower would mean touching real billing, which this menu never does
     * — that's the Stripe dashboard/portal's job.
     */
    #[Route('/{id}/tier/down', name: 'app_admin_user_tier_down', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function tierDown(
        int $id,
        Request $request,
        UserRepository $users,
        EntityManagerInterface $em,
        SubscriptionService $subscriptions,
    ): Response {
        $user = $users->find($id);
        if ($user === null) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('admin-tier-down-' . $id, (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_admin_users');
        }

        $effective = $subscriptions->effectiveTier($user);
        $floor = $subscriptions->paidTier($user);

        if ($effective === SubscriptionTier::Free) {
            $this->addFlash('error', sprintf('%s is already on the Free tier.', $user->getEmail()));

            return $this->redirectToRoute('app_admin_users');
        }
        if ($effective->rank() <= $floor->rank()) {
            $this->addFlash('error', sprintf(
                '%s pays for %s via Stripe — a lower tier means changing their billing, '
                    . 'which is managed in Stripe, not here.',
                $user->getEmail(),
                $floor->label(),
            ));

            return $this->redirectToRoute('app_admin_users');
        }

        // Target is one step down, never below the paid floor. When the comp is no
        // longer doing anything (target == floor), clear it instead of stacking a
        // redundant grant. A time-limited comp KEEPS its expiry when lowered —
        // downgrading a beta grant that lapses tomorrow must not quietly extend it
        // to a permanent one (Copilot catch on PR #179).
        $target = SubscriptionTier::fromRank($effective->rank() - 1);
        \assert($target !== null);
        if ($target->rank() <= $floor->rank()) {
            $subscriptions->revokeComp($user);
        } else {
            $subscriptions->grantComp($user, $target, $user->getCompExpiresAt());
        }
        $em->flush();
        $this->addFlash('success', sprintf('%s downgraded to %s.', $user->getEmail(), $target->label()));

        return $this->redirectToRoute('app_admin_users');
    }

    #[Route('/{id}/delete', name: 'app_admin_user_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(
        int $id,
        Request $request,
        UserRepository $users,
        AccountDeletionService $accountDeletion,
    ): Response {
        $user = $users->find($id);
        if ($user === null) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('admin-delete-' . $id, (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_admin_users');
        }

        if ($user === $this->getUser()) {
            $this->addFlash('error', 'You cannot delete your own account.');

            return $this->redirectToRoute('app_admin_users');
        }

        $email = (string) $user->getEmail();
        $accountDeletion->purgeUser($user);
        $this->addFlash('success', sprintf('%s and all their data were deleted.', $email));

        return $this->redirectToRoute('app_admin_users');
    }

    #[Route(
        '/{id}/cancel-deletion',
        name: 'app_admin_user_cancel_deletion',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function cancelDeletion(
        int $id,
        Request $request,
        UserRepository $users,
        AccountDeletionService $accountDeletion,
    ): Response {
        $user = $users->find($id);
        if ($user === null) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('admin-cancel-deletion-' . $id, (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_admin_users');
        }

        $accountDeletion->cancelDeletion($user);
        $this->addFlash('success', sprintf('Cancelled the pending deletion for %s.', $user->getEmail()));

        return $this->redirectToRoute('app_admin_users');
    }
}
