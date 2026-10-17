<?php

declare(strict_types=1);

namespace App\Controller;

use App\Hosting\Brand;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The web-app manifest (home-screen name and icons), generated so it carries this
 * instance's name: NouriLoop's own icons on the hosted service, a neutral icon on a
 * self-hosted copy (configurable branding, PR 6b). It replaces the static
 * public/site.webmanifest, which named NouriLoop on every copy.
 */
final class ManifestController extends AbstractController
{
    #[Route('/app.webmanifest', name: 'app_manifest', methods: ['GET'])]
    public function manifest(Brand $brand): JsonResponse
    {
        // Same icon rule as the favicon and navbar: the operator's logo when set, else
        // NouriLoop's own icons on the hosted service, else the neutral one.
        $icons = match (true) {
            $brand->logoUrl() !== '' => [['src' => $brand->logoUrl(), 'sizes' => 'any']],
            $brand->isNouriLoop() => [
                // Exactly the old static file's entries (maskable artwork included), so the
                // hosted service's install metadata didn't change (Copilot catch on #246).
                [
                    'src' => '/icon-192x192.png', 'sizes' => '192x192',
                    'type' => 'image/png', 'purpose' => 'any maskable',
                ],
                [
                    'src' => '/icon-512x512.png', 'sizes' => '512x512',
                    'type' => 'image/png', 'purpose' => 'any maskable',
                ],
            ],
            default => [['src' => '/images/neutral-icon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml']],
        };

        $manifest = ['name' => $brand->name(), 'short_name' => $brand->name()];
        if ($brand->isNouriLoop()) {
            $manifest['description'] = 'See what your food does to you.';
        }
        $manifest += [
            'start_url' => '/',
            'display' => 'standalone',
            'background_color' => '#1f2937',
            'theme_color' => '#1f2937',
            'icons' => $icons,
        ];

        $response = new JsonResponse($manifest);
        $response->headers->set('Content-Type', 'application/manifest+json');

        return $response;
    }
}
