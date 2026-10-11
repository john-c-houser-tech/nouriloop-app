<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\AttributionCapture;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Records first-touch campaign parameters (utm_* / gclid) on whichever page an
 * ad drops the visitor on — normally /lp/*, but any page can carry them — so
 * they're still available when the visitor registers a few clicks later.
 *
 * Runs late (priority 5, alongside LocaleSubscriber) purely so the router has
 * already run; it needs no security token. AttributionCapture no-ops unless the
 * URL actually carries a parameter, so this costs an array lookup on every other
 * request and starts no session.
 */
final class AttributionSubscriber implements EventSubscriberInterface
{
    /**
     * Mirrors the stateless `^/api` firewall's pattern in security.yaml. Keep the
     * two in step: this is browser-only bookkeeping and must never start a session
     * for a bearer-token client.
     */
    private const STATELESS_PREFIX = '/api';

    public function __construct(
        private readonly AttributionCapture $attribution,
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

        // GET only: attribution arrives on a link click.
        if (!$request->isMethod('GET')) {
            return;
        }

        // Never on the stateless API firewall. It isn't enough to rely on those
        // routes being POSTs — this very feature adds GET /api/metrics/registrations
        // — and a machine client that happened to send a tracked parameter would
        // otherwise have a session started and a cookie set on a request that is
        // declared stateless. Symfony doesn't mark the request `_stateless` from the
        // firewall config in this version, so match the prefix explicitly.
        if (str_starts_with($request->getPathInfo(), self::STATELESS_PREFIX)) {
            return;
        }

        $this->attribution->captureFromRequest($request);
    }
}
