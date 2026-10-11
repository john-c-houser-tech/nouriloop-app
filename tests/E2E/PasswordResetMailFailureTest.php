<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A reset email that fails to send (#225): no 500, the same check-email page a
 * sent or unknown-address request gets (so nothing reveals that the account
 * exists), and the stored request is dropped so "Try again" sends a fresh link
 * at once instead of being throttled for an hour.
 */
final class PasswordResetMailFailureTest extends WebTestCase
{
    use DbResetTrait;

    public function testTransportFailureLooksLikeASendAndTheRetryIsNotThrottled(): void
    {
        $client = static::createClient();
        // Keep one kernel across requests so the mailer double stays in the container.
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $this->seedUser($em, 'forgetful@test.local');

        // The real mailer can't be fetched and then replaced, so the double sends
        // through the configured transports itself (which feed assertEmailCount).
        $transports = static::getContainer()->get('mailer.transports');
        $mailer = new class (new Mailer($transports)) implements MailerInterface {
            public bool $failing = true;

            public function __construct(private MailerInterface $inner)
            {
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                if ($this->failing) {
                    throw new TransportException('smarthost unreachable');
                }
                $this->inner->send($message, $envelope);
            }
        };
        static::getContainer()->set('mailer.mailer', $mailer);

        $this->requestReset($client, 'forgetful@test.local');
        $this->assertResponseRedirects('/reset-password/check-email');
        $failedPage = $client->followRedirect()->html();
        $em->clear();
        self::assertCount(
            0,
            $em->getRepository(ResetPasswordRequest::class)->findAll(),
            'the failed request is dropped',
        );

        // The page is the one an unknown address gets.
        $this->requestReset($client, 'nobody@test.local');
        $this->assertResponseRedirects('/reset-password/check-email');
        self::assertSame($this->pageBody($failedPage), $this->pageBody($client->followRedirect()->html()));

        // "Try again" right away sends a link instead of being throttled.
        $mailer->failing = false;
        $this->requestReset($client, 'forgetful@test.local');
        $this->assertResponseRedirects('/reset-password/check-email');
        $this->assertEmailCount(1);
        $em->clear();
        self::assertCount(1, $em->getRepository(ResetPasswordRequest::class)->findAll());
    }

    private function requestReset(KernelBrowser $client, string $email): void
    {
        $client->request('GET', '/reset-password');
        $client->submitForm('Send reset link', ['reset_password_request_form[email]' => $email]);
    }

    /** The page's main content, which shouldn't depend on whether the account exists. */
    private function pageBody(string $html): string
    {
        self::assertSame(1, preg_match('#<main[^>]*>(.*)</main>#s', $html, $m), 'page has a <main>');

        return $m[1];
    }

    private function seedUser(EntityManagerInterface $em, string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Reset Tester');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable('-1 day'));
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
