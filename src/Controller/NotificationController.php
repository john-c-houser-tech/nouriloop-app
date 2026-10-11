<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationCategory;
use App\Repository\NotificationRepository;
use App\Repository\NutritionGoalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The in-app notification inbox. Listing the page marks everything read (clearing the
 * navbar bell badge) — the friendly "you've seen your inbox" behaviour. The rows that
 * were unread on arrival are still highlighted on this render so the user can tell
 * what's new; they render plain on the next visit.
 *
 * Notification rows (both the inbox and the bell dropdown) link through `go()` rather
 * than straight to their target, so a click marks that one item read and, if its
 * target has since been deleted (e.g. a goal), lands on the inbox with a notice
 * instead of a raw 404.
 */
class NotificationController extends AbstractController
{
    private const PAGE_SIZE = 50;

    #[Route('/notifications', name: 'app_notifications', methods: ['GET'])]
    public function index(NotificationRepository $notifications): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $rows = $notifications->findRecent($user, self::PAGE_SIZE);

        // Capture what was unread *before* clearing, so this view can highlight it.
        $unreadIds = [];
        foreach ($rows as $row) {
            if (!$row->isRead()) {
                $unreadIds[$row->getId()] = true;
            }
        }

        if ($unreadIds !== []) {
            $notifications->markAllRead($user, new \DateTimeImmutable());
        }

        return $this->render('notification/index.html.twig', [
            'notifications' => $rows,
            'unreadIds'     => $unreadIds,
        ]);
    }

    /**
     * Mark one notification read and forward to its target. If the target no longer
     * exists, bounce back to the inbox with a notice rather than 404.
     */
    #[Route('/notifications/{id}/go', name: 'app_notifications_go', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function go(
        int $id,
        NotificationRepository $notifications,
        NutritionGoalRepository $goals,
        EntityManagerInterface $em,
        TranslatorInterface $translator
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $notification = $notifications->find($id);
        if ($notification === null || $notification->getUser() !== $user) {
            throw $this->createNotFoundException();
        }

        $notification->markRead();
        $em->flush();

        $linkPath = $notification->getLinkPath();
        // Only follow an internal, app-generated relative path: it must start with a
        // single "/" — NOT "//" or "/\" (both of which browsers treat as a
        // protocol-relative absolute URL → off-site). Defence-in-depth: today linkPath
        // always comes from UrlGenerator and can't take that shape, but the guard makes
        // an open redirect impossible regardless.
        if (
            $linkPath === null
            || !str_starts_with($linkPath, '/')
            || str_starts_with($linkPath, '//')
            || str_starts_with($linkPath, '/\\')
        ) {
            return $this->redirectToRoute('app_notifications');
        }

        if ($this->targetIsGone($notification, $user, $goals)) {
            $this->addFlash('info', $translator->trans('inbox.gone'));

            return $this->redirectToRoute('app_notifications');
        }

        return $this->redirect($linkPath);
    }

    /**
     * Whether a notification's deep-link target has been deleted. Only goal check-ins
     * point at a per-resource page that can disappear (the digest categories link to
     * stable index pages that always exist), so that's the only case we re-validate.
     */
    private function targetIsGone(Notification $notification, User $user, NutritionGoalRepository $goals): bool
    {
        if ($notification->getCategory() !== NotificationCategory::GoalCheckin) {
            return false;
        }
        if (!preg_match('#/(\d+)$#', (string) $notification->getLinkPath(), $m)) {
            return false;
        }

        $goal = $goals->find((int) $m[1]);

        return $goal === null || $goal->getUser() !== $user;
    }
}
