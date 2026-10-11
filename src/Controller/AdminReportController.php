<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\FavoriteRepository;
use App\Service\FavoriteManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/reports')]
#[IsGranted('ROLE_ADMIN')]
class AdminReportController extends AbstractController
{
    /**
     * Favorite-reuse savings across all users: how many Claude analyses were
     * skipped and (where the per-analysis cost was captured) how many tokens
     * that saved.
     */
    #[Route('/favorites', name: 'app_admin_favorites_report', methods: ['GET'])]
    public function favorites(FavoriteRepository $favorites): Response
    {
        $rows = $favorites->savingsByUser();

        return $this->render('admin/reports/favorites.html.twig', [
            'rows'   => $rows,
            'totals' => FavoriteManager::reportTotals($rows),
        ]);
    }
}
