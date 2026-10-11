<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\SupportedLocale;
use App\Twig\LocalizedRoutesExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * XML sitemap of NouriLoop's public surface for search engines. The help-topic URLs
 * are derived from HelpController::TOPICS (the single source of truth), so the sitemap
 * can't drift from the real pages. <lastmod> comes from the DEPLOY_DATE env var set on
 * each deploy (falls back to today when unset, e.g. in dev/test).
 */
class SitemapController extends AbstractController
{
    /** Static public routes → [priority, changefreq]. */
    private const STATIC_PAGES = [
        'app_about'         => [1.0, 'monthly'],
        'app_pricing'       => [0.9, 'monthly'],
        'app_help'          => [0.8, 'monthly'],
        'app_privacy_terms' => [0.4, 'yearly'],
        'app_privacy_health' => [0.4, 'yearly'],
        'app_privacy_sms'   => [0.3, 'yearly'],
    ];

    /** Priority overrides for specific help topics (changefreq is always monthly). */
    private const TOPIC_PRIORITY = [
        'log-meal'     => 0.7,
        'glucose'      => 0.7,
        'inflammation' => 0.7,
        'goals'        => 0.7,
        'settings'     => 0.5,
    ];

    /** Priority for any help topic not listed in TOPIC_PRIORITY. */
    private const TOPIC_PRIORITY_DEFAULT = 0.6;

    public function __construct(
        #[Autowire('%env(DEPLOY_DATE)%')]
        private readonly string $deployDate,
    ) {
    }

    #[Route('/sitemap.xml', name: 'app_sitemap', methods: ['GET'])]
    public function index(): Response
    {
        $lastmod = $this->deployDate !== '' ? $this->deployDate : date('Y-m-d');

        $urls = [];
        foreach (self::STATIC_PAGES as $route => [$priority, $changefreq]) {
            $urls[] = [
                'loc'        => $this->generateUrl($route, [], UrlGeneratorInterface::ABSOLUTE_URL),
                'priority'   => number_format($priority, 1),
                'changefreq' => $changefreq,
            ];

            // Locale-prefixed variants (i18n Phase 4) for the routes that have
            // them — each once per locale; hreflang on the pages themselves
            // relates them so Google indexes alternates, not duplicates.
            $twin = LocalizedRoutesExtension::TWINS[$route] ?? null;
            if ($twin === null) {
                continue;
            }
            foreach (SupportedLocale::cases() as $locale) {
                if ($locale === SupportedLocale::English) {
                    continue;
                }
                $urls[] = [
                    'loc'        => $this->generateUrl(
                        $twin,
                        ['_locale' => $locale->urlPrefix()],
                        UrlGeneratorInterface::ABSOLUTE_URL,
                    ),
                    'priority'   => number_format(max(0.1, $priority - 0.2), 1),
                    'changefreq' => $changefreq,
                ];
            }
        }
        // Iterate TOPICS (the single source of truth) so a newly-added help topic always
        // appears in the sitemap; TOPIC_PRIORITY is just an override map with a default.
        foreach (array_keys(HelpController::TOPICS) as $slug) {
            $urls[] = [
                'loc'        => $this->generateUrl(
                    'app_help_show',
                    ['topic' => $slug],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
                'priority'   => number_format(self::TOPIC_PRIORITY[$slug] ?? self::TOPIC_PRIORITY_DEFAULT, 1),
                'changefreq' => 'monthly',
            ];
        }

        $response = $this->render('sitemap/index.xml.twig', [
            'urls'    => $urls,
            'lastmod' => $lastmod,
        ]);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');

        return $response;
    }
}
