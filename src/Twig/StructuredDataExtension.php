<?php

declare(strict_types=1);

namespace App\Twig;

use App\Controller\HelpController;
use App\Enum\SubscriptionTier;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Builds JSON-LD structured data for the public pages (Phase 2 of the SEO plan), emitted
 * inside `<script type="application/ld+json">` via the `seo_jsonld` block. Each function
 * returns a ready-to-print JSON string (slashes left unescaped for clean URLs; `<`/`>`
 * hex-escaped so the payload can't break out of the script tag). All data comes from the
 * app's own constants/routes — never user input — so there's nothing to sanitise.
 */
class StructuredDataExtension extends AbstractExtension
{
    private const ENCODE_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG;

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('jsonld_web_application', $this->webApplication(...)),
            new TwigFunction('jsonld_help_list', $this->helpList(...)),
            new TwigFunction('jsonld_help_article', $this->helpArticle(...)),
        ];
    }

    /** SoftwareApplication/WebApplication schema for /about, with per-tier offers. */
    public function webApplication(): string
    {
        $offers = [];
        foreach (SubscriptionTier::cases() as $tier) {
            $monthly = $tier->monthlyPriceUsd();
            $offer = [
                '@type'         => 'Offer',
                'name'          => $tier->label(),
                'price'         => (string) $monthly,
                'priceCurrency' => 'USD',
            ];
            if ($monthly > 0) {
                // A recurring monthly price ($N per 1 month) — the schema.org-correct
                // shape, unlike a bare non-standard "billingIncrement" on the Offer.
                $offer['priceSpecification'] = [
                    '@type'         => 'UnitPriceSpecification',
                    'price'         => $monthly,
                    'priceCurrency' => 'USD',
                    'unitCode'      => 'MON',
                ];
            }
            $offers[] = $offer;
        }

        return $this->encode([
            '@context'            => 'https://schema.org',
            '@type'              => 'WebApplication',
            'name'               => 'NouriLoop',
            'url'                => $this->base() . '/',
            'applicationCategory' => 'HealthApplication',
            'operatingSystem'    => 'Web',
            // Rendered on /about under the request locale (LocaleSubscriber has
            // run by render time), so the schema description localizes with it.
            'description'        => $this->translator->trans('about.jsonld_description'),
            'offers'             => $offers,
        ]);
    }

    /** ItemList of the help topics for the /help index (accurate; not a forced FAQPage). */
    public function helpList(): string
    {
        $items = [];
        $position = 1;
        foreach (HelpController::TOPICS as $slug => $meta) {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $position++,
                'name'     => $meta['title'],
                'url'      => $this->topicUrl($slug),
            ];
        }

        return $this->encode([
            '@context'        => 'https://schema.org',
            '@type'           => 'ItemList',
            'name'            => 'NouriLoop Help Center',
            'itemListElement' => $items,
        ]);
    }

    /** TechArticle schema for one help topic page. */
    public function helpArticle(string $slug): string
    {
        $meta = HelpController::TOPICS[$slug] ?? null;
        if ($meta === null) {
            return '';
        }

        return $this->encode([
            '@context'    => 'https://schema.org',
            '@type'       => 'TechArticle',
            'headline'    => $meta['title'],
            'description' => $meta['summary'],
            'url'         => $this->topicUrl($slug),
            'image'       => $this->base() . '/images/help/' . $slug . '.png',
            'inLanguage'  => 'en',
            'publisher'   => [
                '@type' => 'Organization',
                'name'  => 'NouriLoop',
                'logo'  => [
                    '@type' => 'ImageObject',
                    'url'   => $this->base() . '/images/og-card.png',
                ],
            ],
        ]);
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        return (string) json_encode($data, self::ENCODE_FLAGS);
    }

    private function topicUrl(string $slug): string
    {
        return $this->urlGenerator->generate(
            'app_help_show',
            ['topic' => $slug],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    /**
     * Absolute site origin (scheme + host [+ non-default port]), no trailing slash.
     * Derived from the routing context, NOT from any page route — so it can't break if
     * a route like the dashboard ever moves off "/". In a web request the context carries
     * the real scheme/host; under CLI it comes from DEFAULT_URI (routing.yaml).
     */
    private function base(): string
    {
        $context = $this->urlGenerator->getContext();
        $scheme = $context->getScheme();

        $port = '';
        if ($scheme === 'http' && $context->getHttpPort() !== 80) {
            $port = ':' . $context->getHttpPort();
        } elseif ($scheme === 'https' && $context->getHttpsPort() !== 443) {
            $port = ':' . $context->getHttpsPort();
        }

        return $scheme . '://' . $context->getHost() . $port;
    }
}
