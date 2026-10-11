<?php

declare(strict_types=1);

namespace App\Twig;

use App\Enum\SupportedLocale;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The locale-prefixed public conversion pages (i18n Phase 4, /es/about …).
 *
 * - `locale_alternates()` — hreflang map for the current page (`x-default` +
 *   every locale variant, absolute URLs), or null when the page isn't one of
 *   the localized routes. The /lp/* pages are deliberately excluded: they're
 *   noindex'd paid-traffic pages, so hreflang would be dead weight — ads
 *   target a locale variant directly instead.
 * - `localized_path(name, params)` — like path(), but when the visitor is on
 *   a locale-prefixed URL and the target route has a localized twin, the link
 *   keeps them in their language (/es/about's register CTA → /es/register).
 *   On bare URLs it behaves exactly like path().
 */
final class LocalizedRoutesExtension extends AbstractExtension
{
    /** bare route name => its {_locale}-prefixed twin */
    public const TWINS = [
        'app_about'        => 'app_about_i18n',
        'app_pricing'      => 'app_pricing_i18n',
        'app_login'        => 'app_login_i18n',
        'app_register'     => 'app_register_i18n',
        'app_landing_page' => 'app_landing_page_i18n',
    ];

    /** Routes kept out of hreflang emission (noindex'd — see class docblock). */
    private const NO_HREFLANG = ['app_landing_page'];

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('locale_alternates', $this->localeAlternates(...)),
            new TwigFunction('localized_path', $this->localizedPath(...)),
        ];
    }

    /** @return array<string, string>|null hreflang => absolute URL */
    public function localeAlternates(): ?array
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return null;
        }

        $route = (string) $request->attributes->get('_route');
        $bare = $this->bareRouteFor($route);
        if ($bare === null || \in_array($bare, self::NO_HREFLANG, true)) {
            return null;
        }

        /** @var array<string, mixed> $params */
        $params = (array) $request->attributes->get('_route_params', []);
        unset($params['_locale']);

        $enUrl = $this->urlGenerator->generate($bare, $params, UrlGeneratorInterface::ABSOLUTE_URL);
        $alternates = ['x-default' => $enUrl, 'en' => $enUrl];
        foreach (SupportedLocale::cases() as $locale) {
            if ($locale === SupportedLocale::English) {
                continue;
            }
            $alternates[$locale->hreflang()] = $this->urlGenerator->generate(
                self::TWINS[$bare],
                $params + ['_locale' => $locale->urlPrefix()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
        }

        return $alternates;
    }

    /** @param array<string, mixed> $params */
    public function localizedPath(string $name, array $params = []): string
    {
        $prefix = $this->activeUrlPrefix();
        if ($prefix !== null && isset(self::TWINS[$name])) {
            return $this->urlGenerator->generate(self::TWINS[$name], $params + ['_locale' => $prefix]);
        }

        return $this->urlGenerator->generate($name, $params);
    }

    /** The dashed URL segment when the current page is locale-prefixed, else null. */
    private function activeUrlPrefix(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        $prefix = $request?->attributes->get('_locale');

        return \is_string($prefix) && $prefix !== '' && SupportedLocale::fromUrlPrefix($prefix) !== null
            ? strtolower($prefix)
            : null;
    }

    private function bareRouteFor(string $route): ?string
    {
        if (isset(self::TWINS[$route])) {
            return $route;
        }
        $bare = array_search($route, self::TWINS, true);

        return $bare === false ? null : $bare;
    }
}
