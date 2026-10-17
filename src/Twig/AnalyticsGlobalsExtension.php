<?php

declare(strict_types=1);

namespace App\Twig;

use App\Hosting\Brand;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * `google_analytics_id` and `meta_pixel_id` for every template. They come from
 * GOOGLE_ANALYTICS_ID / META_PIXEL_ID as before (empty in dev/test/CI), but only
 * NouriLoop's own service ever uses them: they are NouriLoop LLC's analytics accounts, so a
 * self-hosted copy gets '' and loads no tracker, whatever its environment says
 * (configurable branding, PR 6b). Every `{% if google_analytics_id %}` in the templates
 * then does the right thing unchanged.
 */
final class AnalyticsGlobalsExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly Brand $brand,
        #[Autowire('%env(GOOGLE_ANALYTICS_ID)%')]
        private readonly string $googleAnalyticsId,
        #[Autowire('%env(META_PIXEL_ID)%')]
        private readonly string $metaPixelId,
    ) {
    }

    /** @return array{google_analytics_id: string, meta_pixel_id: string} */
    public function getGlobals(): array
    {
        $ours = $this->brand->isNouriLoop();

        return [
            'google_analytics_id' => $ours ? $this->googleAnalyticsId : '',
            'meta_pixel_id' => $ours ? $this->metaPixelId : '',
        ];
    }
}
