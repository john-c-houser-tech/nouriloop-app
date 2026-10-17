<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\SupportedLocale;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Ad-specific landing pages (Marketing Plan §8): each paid campaign lands on a page
 * matching its audience's intent instead of the generic /about overview. Public
 * (^/lp is PUBLIC_ACCESS), noindex'd (paid-traffic pages, deliberately out of the
 * sitemap/organic index — see lp/_layout.html.twig), and navigation-free so the
 * only path forward is the register CTA.
 *
 * Copy constraint: the diabetes page says "blood sugar"/"glucose", never
 * "diabetes" — the slug is an internal campaign name, not displayed ad text
 * (Marketing Plan §2, ad policy constraint). The rule holds in every locale's
 * catalog, not just English.
 */
class LandingPageController extends AbstractController
{
    /**
     * slug → seo_description translation key (lp.<slug>.seo_description, in the
     * messages+intl-icu catalogs). Single source of truth for which landing pages
     * exist; show() 404s on anything else. Slugs match the campaign names used in
     * the Marketing Plan and the ads' final URLs.
     */
    public const PAGES = [
        'diabetes'     => 'lp.diabetes.seo_description',
        'inflammation' => 'lp.inflammation.seo_description',
        'fiber'        => 'lp.fiber.seo_description',
        'weight-loss'  => 'lp.weight_loss.seo_description',
    ];

    // NouriLoop's ad landing pages: hosted only (configurable branding, PR 6b).
    #[Route('/lp/{slug}', name: 'app_landing_page', methods: ['GET'], condition: "service('hosting').isHosted()")]
    #[Route(
        '/{_locale}/lp/{slug}',
        name: 'app_landing_page_i18n',
        requirements: ['_locale' => SupportedLocale::URL_PREFIX_REQUIREMENT],
        methods: ['GET'],
        condition: "service('hosting').isHosted()",
    )]
    public function show(string $slug, TranslatorInterface $translator): Response
    {
        if (!isset(self::PAGES[$slug])) {
            throw $this->createNotFoundException('Unknown landing page.');
        }

        return $this->render('lp/' . $slug . '.html.twig', [
            // Translated at render time — the request locale is already set.
            'seo_description' => $translator->trans(self::PAGES[$slug]),
        ]);
    }
}
