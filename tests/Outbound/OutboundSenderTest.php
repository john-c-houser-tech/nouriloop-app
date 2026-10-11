<?php

declare(strict_types=1);

namespace App\Tests\Outbound;

use App\Entity\User;
use App\Outbound\OutboundSender;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Notifier\TexterInterface;

final class OutboundSenderTest extends TestCase
{
    /** @var list<array{0: string, 1: string}> */
    private array $logged = [];

    public function testAFailedEmailIsAWarningNotAnException(): void
    {
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willThrowException(new \RuntimeException('relay down'));

        $sent = $this->sender($mailer, $this->createStub(TexterInterface::class))
            ->sendEmail((new Email())->to('a@test.local'), new User(), 'Digest');

        self::assertFalse($sent);
        self::assertSame([['warning', '{what} email to user {user} failed: {error}']], $this->logged);
    }

    public function testAnSmsIsLoggedThenSentAndAFailureIsAWarning(): void
    {
        $texter = $this->createStub(TexterInterface::class);
        $texter->method('send')->willThrowException(new \RuntimeException('no TWILIO_DSN'));
        $user = (new User())->setPhoneNumber('+15035551234');

        $sender = $this->sender($this->createStub(MailerInterface::class), $texter);
        $sent = $sender->sendSms($user, 'hi', 'Goal check-in');

        self::assertFalse($sent);
        self::assertSame(
            [['info', '{what} SMS to {phone}: {body}'], ['warning', '{what} SMS send failed: {error}']],
            $this->logged,
        );
    }

    public function testASuccessfulSendIsTrue(): void
    {
        $sender = $this->sender($this->createStub(MailerInterface::class), $this->createStub(TexterInterface::class));

        self::assertTrue($sender->sendEmail((new Email())->to('a@test.local'), new User(), 'Digest'));
    }

    private function sender(MailerInterface $mailer, TexterInterface $texter): OutboundSender
    {
        $logger = new class ($this->logged) extends AbstractLogger {
            /** @param list<array{0: string, 1: string}> $logged */
            public function __construct(private array &$logged)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = [(string) $level, (string) $message];
            }
        };

        return new OutboundSender($mailer, $texter, $logger);
    }
}
