<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationCategory;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The in-app notification center, end-to-end against the real DB (in-process
 * WebTestCase, so it lives here for the DB but excludes from the DB-less unit job):
 * the navbar bell badge, the inbox listing, and the mark-read-on-view behaviour.
 */
final class NotificationCenterTest extends WebTestCase
{
    use DbResetTrait;

    public function testBellBadgeShowsUnreadCountAndInboxListsItems(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $user = $this->seedUser($em, 'bell@test.local');
        $this->seedNotification($em, $user, 'Fiber intake — On track', '/goals/1');
        $this->seedNotification($em, $user, 'No meals logged today', '/meal/log');

        $client->loginUser($user);

        // The bell badge shows the unread count on any page.
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.navbar .badge.bg-danger', '2');

        // The inbox lists both notifications.
        $crawler = $client->request('GET', '/notifications');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Notifications');
        self::assertSelectorTextContains('.list-group', 'Fiber intake — On track');
        self::assertSelectorTextContains('.list-group', 'No meals logged today');
        // Both were unread on arrival → highlighted as "New".
        self::assertSame(2, $crawler->filter('.badge:contains("New")')->count());
    }

    public function testVisitingInboxMarksEverythingRead(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $user = $this->seedUser($em, 'read@test.local');
        $this->seedNotification($em, $user, 'Weekly supplement suggestions', '/supplements');

        $client->loginUser($user);

        $repo = static::getContainer()->get(NotificationRepository::class);
        self::assertSame(1, $repo->countUnread($user));

        $client->request('GET', '/notifications');
        self::assertResponseIsSuccessful();

        $em->clear();
        $reloaded = $em->getRepository(User::class)->findOneBy(['email' => 'read@test.local']);
        self::assertSame(0, $repo->countUnread($reloaded));

        // Badge is gone on the next page load.
        $client->request('GET', '/');
        self::assertSelectorNotExists('.navbar .badge.bg-danger');
    }

    public function testEmptyInboxRendersFriendlyState(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $user = $this->seedUser($em, 'empty@test.local');
        $client->loginUser($user);

        $client->request('GET', '/notifications');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card', 'No notifications yet');
        self::assertSelectorNotExists('.navbar .badge.bg-danger');
    }

    public function testClickingNotificationMarksItReadAndRedirectsToTarget(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $user = $this->seedUser($em, 'go@test.local');
        // A digest-category link points at a stable index page that always exists.
        $n = $this->seedNotification(
            $em,
            $user,
            'Weekly supplement suggestions',
            '/supplements',
            NotificationCategory::Supplements,
        );
        $id = $n->getId();

        $client->loginUser($user);
        $client->request('GET', '/notifications/' . $id . '/go');
        self::assertResponseRedirects('/supplements');

        $em->clear();
        $reloaded = $em->getRepository(Notification::class)->find($id);
        self::assertTrue($reloaded->isRead(), 'clicking through should mark that item read');
    }

    public function testGoalNotificationWithDeletedTargetRedirectsToInboxWithNotice(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $user = $this->seedUser($em, 'gone@test.local');
        // A goal check-in pointing at a goal id that no longer exists.
        $n = $this->seedNotification($em, $user, 'Fiber — On track', '/goals/999999');

        $client->loginUser($user);
        $client->request('GET', '/notifications/' . $n->getId() . '/go');
        self::assertResponseRedirects('/notifications');

        $client->followRedirect();
        self::assertSelectorTextContains('.alert', 'no longer available');
    }

    public function testCannotOpenAnotherUsersNotification(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $owner = $this->seedUser($em, 'owner@test.local');
        $other = $this->seedUser($em, 'other@test.local');
        $n = $this->seedNotification($em, $owner, 'Private', '/supplements', NotificationCategory::Supplements);

        $client->loginUser($other);
        $client->request('GET', '/notifications/' . $n->getId() . '/go');
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * A protocol-relative path (which can't be produced today, but is the open-redirect
     * shape) must never be followed off-site — the click falls back to the inbox.
     */
    public function testProtocolRelativeLinkIsNotFollowedOffSite(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $user = $this->seedUser($em, 'redir@test.local');
        $n = $this->seedNotification($em, $user, 'Sketchy', '//evil.example.com', NotificationCategory::Supplements);

        $client->loginUser($user);
        $client->request('GET', '/notifications/' . $n->getId() . '/go');
        self::assertResponseRedirects('/notifications');
    }

    /**
     * Regression: the navbar bell's `{% set %}` of recent items must not shadow a page
     * variable named `recent` (the /symptoms history) in a child template's body block.
     * Pre-fix, a logged-in user *with notifications* 500'd on /symptoms because `recent`
     * resolved to the notification list and the history loop called `.loggedDate` on it.
     */
    public function testNotificationsDoNotShadowThePageRecentVariable(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $user = $this->seedUser($em, 'shadow@test.local');
        // A non-empty dropdown is what triggered the shadow bug.
        $this->seedNotification($em, $user, 'Fiber — On track', '/goals/1');

        $client->loginUser($user);
        $client->request('GET', '/symptoms');
        self::assertResponseIsSuccessful();
    }

    private function seedUser(EntityManagerInterface $em, string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Seeded User');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setTimezone('America/New_York');
        // Verified so the unverified-email banner (its form/_token would be picked up
        // by naive crawler selectors) stays out of these flows.
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function seedNotification(
        EntityManagerInterface $em,
        User $user,
        string $title,
        string $link,
        NotificationCategory $category = NotificationCategory::GoalCheckin,
    ): Notification {
        $notification = new Notification($user, $category, $title, 'Body text.', $link);
        $em->persist($notification);
        $em->flush();

        return $notification;
    }
}
