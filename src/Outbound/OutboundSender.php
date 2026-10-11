<?php

declare(strict_types=1);

namespace App\Outbound;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\TexterInterface;

/**
 * Sends a scheduled message in isolation: a transport failure is logged as a
 * warning and reported as false, never thrown, so one failed channel can't
 * block another, abort a run, or skip the cooldown log after it. A failed
 * outbound message is an expected, retry-later event, not an application error.
 */
final class OutboundSender
{
    public function __construct(
        private readonly MailerInterface $mailer,
        // Lazy so a missing/empty TWILIO_DSN only errors when an SMS is actually
        // sent (caught below), not when this is constructed.
        #[Autowire(lazy: true)]
        private readonly TexterInterface $texter,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param string $what names the message in the log, e.g. 'Digest' */
    public function sendEmail(Email $email, User $user, string $what): bool
    {
        try {
            $this->mailer->send($email);

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('{what} email to user {user} failed: {error}', [
                'what'  => $what,
                'user'  => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** @param string $what names the message in the log, e.g. 'Goal check-in' */
    public function sendSms(User $user, string $body, string $what): bool
    {
        // The body is logged so delivery is observable even before Twilio is
        // configured, or when the transport fails.
        $this->logger->info('{what} SMS to {phone}: {body}', [
            'what'  => $what,
            'phone' => $user->getPhoneNumber(),
            'body'  => $body,
        ]);

        try {
            $this->texter->send(new SmsMessage((string) $user->getPhoneNumber(), $body));

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('{what} SMS send failed: {error}', ['what' => $what, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
