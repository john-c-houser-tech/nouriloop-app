<?php

declare(strict_types=1);

namespace App\Enum;

enum NotificationChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email only',
            self::Sms   => 'Text message only',
            self::Both  => 'Email and text',
        };
    }

    /** Translation key for UI rendering (i18n); label() stays for PHP contexts. */
    public function transKey(): string
    {
        return 'settings.channel.' . $this->value;
    }

    public function includesEmail(): bool
    {
        return $this === self::Email || $this === self::Both;
    }

    public function includesSms(): bool
    {
        return $this === self::Sms || $this === self::Both;
    }
}
