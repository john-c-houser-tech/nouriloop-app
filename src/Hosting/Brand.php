<?php

declare(strict_types=1);

namespace App\Hosting;

/**
 * What this instance is called and who runs it (configurable branding, ADR 0003, App
 * Development Plan PR 6b / Q5, Q11). NouriLoop's own name, logo, company pages and legal
 * documents belong to NouriLoop LLC (the README's trademark note), so a self-hosted copy
 * carries its operator's:
 *
 *  - name: `APP_NAME`, else "NouriLoop" on the hosted service, else the copy's own domain
 *    (the host of `DEFAULT_URI`, `www.` dropped — Q11a);
 *  - logo: `APP_LOGO_URL`, else NouriLoop's on the hosted service, else a neutral icon
 *    (templates/_brand_mark.html.twig picks the right size of NouriLoop's own);
 *  - legal pages: `PRIVACY_URL` / `TERMS_URL`, else a short built-in page naming
 *    `OPERATOR_EMAIL` (Q11c);
 *  - source: `SOURCE_URL` (AGPL §13: a modified copy must offer its own source, Q11d).
 *
 * Exposed to templates as the `brand` global and to every translation as `{app_name}`.
 */
final class Brand
{
    public const NOURILOOP = 'NouriLoop';
    public const DEFAULT_SOURCE_URL = 'https://github.com/john-c-houser-tech/nouriloop-app';

    private readonly string $name;

    public function __construct(
        private readonly HostingMode $hosting,
        string $appName,
        string $defaultUri,
        private readonly string $logoUrl,
        private readonly string $sourceUrl,
        private readonly string $operatorEmail,
        private readonly string $privacyUrl,
        private readonly string $termsUrl,
    ) {
        $this->name = self::resolveName($appName, $hosting->isHosted(), $defaultUri);
    }

    /**
     * The one naming rule (Q11a), shared with {@see BrandNameEnvVarProcessor} so templates
     * and translations can't disagree: `APP_NAME`, else "NouriLoop" when hosted, else the
     * copy's own domain.
     */
    public static function resolveName(string $appName, bool $hosted, string $defaultUri): string
    {
        if (trim($appName) !== '') {
            return trim($appName);
        }

        return $hosted ? self::NOURILOOP : self::domainOf($defaultUri);
    }

    /** True on NouriLoop's own service: its company pages, legal documents and logo apply. */
    public function isNouriLoop(): bool
    {
        return $this->hosting->isHosted();
    }

    public function name(): string
    {
        return $this->name;
    }

    /** The operator's own logo (`APP_LOGO_URL`), or '' — then NouriLoop's or a neutral icon. */
    public function logoUrl(): string
    {
        return trim($this->logoUrl);
    }

    public function sourceUrl(): string
    {
        return trim($this->sourceUrl) !== '' ? trim($this->sourceUrl) : self::DEFAULT_SOURCE_URL;
    }

    /** Who to contact about a self-hosted copy; '' when the operator didn't say. */
    public function operatorEmail(): string
    {
        return trim($this->operatorEmail);
    }

    /** The operator's privacy policy URL, or '' (then the built-in page is shown). */
    public function privacyUrl(): string
    {
        return trim($this->privacyUrl);
    }

    /** The operator's terms URL, or '' (then the built-in page is shown). */
    public function termsUrl(): string
    {
        return trim($this->termsUrl);
    }

    /** "food.example.org" from "https://www.food.example.org/"; the URI itself if unparseable. */
    private static function domainOf(string $uri): string
    {
        $host = (string) parse_url(trim($uri), PHP_URL_HOST);
        if ($host === '') {
            return trim($uri) !== '' ? trim($uri) : 'localhost';
        }

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
