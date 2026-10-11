<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Enum\SupportedLocale;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Sets the locale the app renders in: the signed-in user's saved preference
 * (Settings ▸ Language), else the best Accept-Language match among
 * SupportedLocale — so the login/registration pages already greet an anonymous
 * visitor in their language once those strings are extracted.
 *
 * Priority 5 — it must run after the firewall listener (priority 8), or the
 * security token isn't available yet. But LocaleAwareListener has already
 * synced the translator from the request locale at priority 15 by then, so
 * setting only `$request->setLocale()` is too late for the translator: the
 * LocaleSwitcher call is what re-propagates the locale to every LocaleAware
 * service. Both are needed (the request locale drives `app.request.locale`,
 * e.g. the `<html lang>` attribute). Display-only — storage stays user-local
 * wall-clock and locale-independent (see CLAUDE.md ▸ Timezones).
 */
final class LocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly LocaleSwitcher $localeSwitcher,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 5]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // A locale-prefixed public URL (/es/about — i18n Phase 4) wins over
        // everything: it's an explicit, shareable choice, and Google crawls it
        // as its own page. RouterListener has already stamped the raw dashed
        // prefix ('es-mx') as the request locale; normalize it to the enum
        // value. Bare URLs fall through to the old preference logic.
        $urlLocale = null;
        $prefix = $request->attributes->get('_locale');
        if (\is_string($prefix) && $prefix !== '') {
            $urlLocale = SupportedLocale::fromUrlPrefix($prefix);
        }

        $user = $this->tokenStorage->getToken()?->getUser();

        $locale = $urlLocale
            ?? ($user instanceof User ? $user->getLocale() : SupportedLocale::fromRequest($request));

        $request->setLocale($locale->value);
        $this->localeSwitcher->setLocale($locale->value);
    }
}
