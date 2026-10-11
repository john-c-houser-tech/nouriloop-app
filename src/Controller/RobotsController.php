<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Serves /robots.txt from a controller (rather than a static public/ file) so it is
 * covered by the firewall's PUBLIC_ACCESS rule, testable in WebTestCase, and the
 * Sitemap line stays in sync with the real route across environments.
 *
 * Only the public marketing/help/legal surface is crawlable; everything that requires
 * a login is disallowed. Note the dashboard lives at "/" (not "/dashboard"), and we
 * can't Disallow "/" without blocking the whole site — anonymous crawlers hitting "/"
 * are 302'd to /login, so authenticated content is never indexable anyway.
 */
class RobotsController extends AbstractController
{
    #[Route('/robots.txt', name: 'app_robots', methods: ['GET'])]
    public function index(): Response
    {
        $sitemap = $this->generateUrl('app_sitemap', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $body = <<<ROBOTS
            User-agent: *
            Allow: /about
            Allow: /help
            Allow: /pricing
            Allow: /privacy/
            Allow: /login
            Allow: /register

            Disallow: /api/
            Disallow: /admin/
            Disallow: /meal/
            Disallow: /goals/
            Disallow: /settings/
            Disallow: /supplements/
            Disallow: /symptoms/
            Disallow: /health-metrics/
            Disallow: /insights/
            Disallow: /trends
            Disallow: /notifications
            Disallow: /subscription/

            Sitemap: $sitemap

            ROBOTS;

        return new Response($body, Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
