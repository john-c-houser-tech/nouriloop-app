<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ScenarioRequest;
use App\Entity\User;
use App\Enum\ScenarioType;
use App\Repository\FavoriteRepository;
use App\Repository\SupplementRepository;
use App\Service\PredictiveInsightService;
use App\Service\SubscriptionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/insights/predict', name: 'app_predict')]
class PredictiveInsightController extends AbstractController
{
    private const ALLOWED_DIMENSIONS = ['inflammation', 'nutrition', 'weight', 'symptoms'];
    private const ALLOWED_WEEKS      = [1, 2, 4];
    private const FREE_TEXT_MAX_LEN  = 300;

    #[Route('', name: '_index', methods: ['GET'])]
    public function index(
        FavoriteRepository $favorites,
        SupplementRepository $supplements,
        SubscriptionService $subscriptions,
        TranslatorInterface $translator,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        if (!$subscriptions->hasFeature($user, 'predictive_help')) {
            $this->addFlash('warning', $translator->trans('predict.loop_plus_only'));
            return $this->redirectToRoute('app_pricing');
        }

        return $this->render('insights/predict.html.twig', [
            'favorites'          => $favorites->findByUserOrdered($user),
            'supplements'        => $supplements->findByUserOrdered($user),
            'scenarioTypes'      => ScenarioType::cases(),
            'insight'            => null,
            'req'                => null,
            'errors'             => [],
        ]);
    }

    #[Route('/run', name: '_run', methods: ['POST'])]
    public function run(
        Request $request,
        PredictiveInsightService $predictor,
        FavoriteRepository $favorites,
        SupplementRepository $supplements,
        SubscriptionService $subscriptions,
        #[Target('predictive_insight')] RateLimiterFactoryInterface $limiter,
        TranslatorInterface $translator,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        if (!$subscriptions->hasFeature($user, 'predictive_help')) {
            $this->addFlash('warning', $translator->trans('predict.loop_plus_only'));
            return $this->redirectToRoute('app_pricing');
        }

        // Same-origin guard before spending the rate limit / Claude tokens.
        if (!$this->isCsrfTokenValid('predict-run', (string) $request->request->get('_token'))) {
            $this->addFlash('warning', $translator->trans('common.session_expired'));
            return $this->redirectToRoute('app_predict_index');
        }

        $limit = $limiter->create('predict-user-' . $user->getUserIdentifier());
        if (!$limit->consume(1)->isAccepted()) {
            $this->addFlash('warning', $translator->trans('predict.rate_limited'));
            return $this->redirectToRoute('app_predict_index');
        }

        $errors = $this->validate($request, $user, $favorites, $supplements, $translator);
        if ($errors !== []) {
            return $this->render('insights/predict.html.twig', [
                'favorites'     => $favorites->findByUserOrdered($user),
                'supplements'   => $supplements->findByUserOrdered($user),
                'scenarioTypes' => ScenarioType::cases(),
                'insight'       => null,
                'req'           => null,
                'errors'        => $errors,
                'posted'        => $request->request->all(),
            ]);
        }

        $req     = $this->buildRequest($request, $user, $favorites, $supplements);
        $insight = $predictor->compute($user, $req);

        return $this->render('insights/predict.html.twig', [
            'favorites'     => $favorites->findByUserOrdered($user),
            'supplements'   => $supplements->findByUserOrdered($user),
            'scenarioTypes' => ScenarioType::cases(),
            'insight'       => $insight,
            'req'           => $req,
            'errors'        => [],
            'posted'        => $request->request->all(),
        ]);
    }

    /** @return list<string> */
    private function validate(
        Request $request,
        User $user,
        FavoriteRepository $favorites,
        SupplementRepository $supplements,
        TranslatorInterface $translator,
    ): array {
        $errors = [];
        $p = $request->request;

        $type = ScenarioType::tryFrom((string) $p->get('type', ''));
        if ($type === null) {
            $errors[] = $translator->trans('predict.err_type');
            return $errors; // can't validate further without a type
        }

        $weeks = (int) $p->get('duration_weeks', 0);
        if (!in_array($weeks, self::ALLOWED_WEEKS, true)) {
            $errors[] = $translator->trans('predict.err_window');
        }

        $dimensions = (array) $p->all('dimensions');
        $validDims  = array_intersect($dimensions, self::ALLOWED_DIMENSIONS);
        if ($validDims === []) {
            $errors[] = $translator->trans('predict.err_dimensions');
        }

        if ($type->requiresFavorite()) {
            $favId = (int) $p->get('favorite_id', 0);
            $fav   = $favId > 0 ? $favorites->find($favId) : null;
            if ($fav === null || $fav->getUser() !== $user) {
                $errors[] = $translator->trans('predict.err_favorite');
            }
        }

        if ($type->requiresSupplement()) {
            $suppId = (int) $p->get('supplement_id', 0);
            $supp   = $suppId > 0 ? $supplements->find($suppId) : null;
            if ($supp === null || $supp->getUser() !== $user) {
                $errors[] = $translator->trans('predict.err_supplement');
            } elseif ($type === ScenarioType::SupplementRemove && !$supp->isActive()) {
                // Removing only makes sense for a supplement already in the active stack —
                // the baseline scores only fold in active supplements.
                $errors[] = $translator->trans('predict.err_remove_inactive');
            } elseif ($type === ScenarioType::SupplementAdd && $supp->isActive()) {
                // Adding one already active would double-count it against the baseline.
                $errors[] = $translator->trans('predict.err_add_active');
            }
        }

        if ($type->requiresFreeText()) {
            $text = trim((string) $p->get('free_text', ''));
            if ($text === '') {
                $errors[] = $translator->trans('predict.err_free_text');
            } elseif (mb_strlen($text) > self::FREE_TEXT_MAX_LEN) {
                $errors[] = $translator->trans('predict.err_free_text_len', ['max' => self::FREE_TEXT_MAX_LEN]);
            }
        }

        return $errors;
    }

    private function buildRequest(
        Request $request,
        User $user,
        FavoriteRepository $favorites,
        SupplementRepository $supplements,
    ): ScenarioRequest {
        $p     = $request->request;
        $type  = ScenarioType::from((string) $p->get('type'));
        $weeks = (int) $p->get('duration_weeks');
        $dims  = array_values(array_intersect((array) $p->all('dimensions'), self::ALLOWED_DIMENSIONS));

        $freeText = null;
        if ($type->requiresFreeText()) {
            $freeText = mb_substr(trim((string) $p->get('free_text')), 0, self::FREE_TEXT_MAX_LEN);
        }

        return new ScenarioRequest(
            type: $type,
            durationWeeks: $weeks,
            dimensions: $dims,
            favoriteId: $type->requiresFavorite() ? (int) $p->get('favorite_id') : null,
            supplementId: $type->requiresSupplement() ? (int) $p->get('supplement_id') : null,
            freeText: $freeText,
        );
    }
}
