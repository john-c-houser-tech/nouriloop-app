<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\SupportedLocale;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class AboutController extends AbstractController
{
    /**
     * Public marketing overview of NouriLoop's features, linked from the login
     * page so prospective users can learn about the product without an account.
     */
    #[Route('/about', name: 'app_about', methods: ['GET'])]
    #[Route(
        '/{_locale}/about',
        name: 'app_about_i18n',
        requirements: ['_locale' => SupportedLocale::URL_PREFIX_REQUIREMENT],
        methods: ['GET'],
    )]
    public function index(TranslatorInterface $translator): Response
    {
        return $this->render('about/index.html.twig', [
            // Translated at render time — LocaleSubscriber has already set the
            // request locale (URL prefix or Accept-Language) by now.
            'seo_description' => $translator->trans('about.seo_description'),
        ]);
    }
}
