<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\StagedPhoto;
use App\Draft\DraftSession;
use App\Draft\RecipeDraft;
use App\Entity\NutritionData;
use App\Entity\User;
use App\Enum\StagingPurpose;
use App\Exception\PhotoLimitExceededException;
use App\Exception\PhotoStagingFailed;
use App\Form\RecipePhotoFormType;
use App\Service\AnalysisAllowance;
use App\Service\ClarifyRound;
use App\Service\FavoriteManager;
use App\Service\PhotoStaging;
use App\Service\PortionScaler;
use App\Service\RecipeAnalysisService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Recipe photo import: photograph a recipe (cookbook page, printout, or a
 * handwritten card across up to 5 photos), let one Claude vision call read the
 * ingredients + yield + batch nutrition, review/correct it, and save it as a
 * normal Favorite divided down to one serving. Session-staged between upload
 * and confirm, the SupplementsController pattern; the confirm screen also
 * supports meal-style re-analysis rounds (answered questions / corrected
 * ingredients) while the photos are still on disk.
 *
 * Photos live in the meals uploads dir so the daily app:cleanup-meal-uploads
 * sweep covers abandoned imports; every kept artifact (the favorite thumbnail)
 * is an independent copy in uploads/favorites.
 */
#[Route('/meal/recipe')]
class RecipeImportController extends AbstractController
{
    private const INSTRUCTIONS_MAX_LENGTH = 10000;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly PhotoStaging $staging,
        private readonly DraftSession $drafts,
    ) {
    }

    #[Route('/scan', name: 'app_recipe_scan', methods: ['GET', 'POST'])]
    public function scan(
        Request $request,
        RecipeAnalysisService $analyzer,
        AnalysisAllowance $allowance,
        SessionInterface $session,
    ): Response {
        $form = $this->createForm(RecipePhotoFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile[] $imageFiles */
            $imageFiles = $form->get('images')->getData();

            try {
                $staged = $this->staging->stage($imageFiles, StagingPurpose::Recipe);
            } catch (PhotoStagingFailed $e) {
                $this->logger->warning('Photo staging failed: {error}', ['error' => $e->getMessage()]);
                $this->addFlash('error', $e->invalidFileMessage() ?? $this->translator->trans('common.heic_failed'));

                return $this->renderScan($form, $allowance);
            }
            $paths = array_map(static fn(StagedPhoto $p): string => $p->path, $staged);

            try {
                $result = $analyzer->analyzeImages($this->user(), $paths);
            } catch (PhotoLimitExceededException $e) {
                $this->staging->discard($staged);
                $this->addFlash('warning', $this->translator->trans($e->messageKey(), $e->messageParameters()));

                return $this->redirectToRoute($e->redirectRoute());
            } catch (\Throwable $e) {
                $this->staging->discard($staged);
                $this->logger->error('Recipe photo analysis failed: {error}', ['error' => $e->getMessage()]);
                $this->addFlash('error', $this->translator->trans('recipe.scan.failed'));

                return $this->renderScan($form, $allowance);
            }

            $this->drafts->store($session, RecipeDraft::fromScan($result, $staged));

            return $this->redirectToRoute('app_recipe_confirm');
        }

        return $this->renderScan($form, $allowance);
    }

    #[Route('/confirm', name: 'app_recipe_confirm', methods: ['GET', 'POST'])]
    public function confirm(
        Request $request,
        SessionInterface $session,
        RecipeAnalysisService $analyzer,
        FavoriteManager $favorites,
        PortionScaler $portionScaler,
    ): Response {
        $draft = $this->drafts->load($session, RecipeDraft::class);
        if ($draft === null) {
            return $this->redirectToRoute('app_recipe_scan');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('recipe-confirm', (string) $request->request->get('_token'))) {
                return $this->redirectToRoute('app_recipe_confirm');
            }

            // Server-side bounds mirror the HTML attributes — a crafted POST
            // can skip them: name fits the VARCHAR(200), servings must be a
            // finite positive number (INF passes a plain > 0 check and would
            // reach the nutrient division and the DOUBLE column).
            $title = mb_substr(trim((string) $request->request->get('title', '')), 0, 200);
            $servings = (float) $request->request->get('servings', 0);
            $servingsValid = is_finite($servings) && $servings > 0;
            $ingredients = ClarifyRound::postedIngredients($request) ?? [];
            $instructions = mb_substr(
                trim((string) $request->request->get('instructions', '')),
                0,
                self::INSTRUCTIONS_MAX_LENGTH,
            );

            if ($request->request->get('action') === 'reanalyze') {
                return $this->reanalyze(
                    $request,
                    $session,
                    $draft,
                    $analyzer,
                    $title,
                    $servingsValid ? $servings : 0.0,
                    $instructions,
                );
            }

            if (!$servingsValid) {
                $this->addFlash('error', $this->translator->trans('recipe.confirm.bad_servings'));

                return $this->redirectToRoute('app_recipe_confirm');
            }

            // Divide the whole-batch estimate down to ONE serving — the shape
            // every favorite stores, so logging at any portion multiple just
            // works. Confidence isn't scaled (fromArray leaves it alone).
            $scale = 1.0 / $servings;
            $perServing = NutritionData::fromArray($draft->nutrients, $scale)->toArray();

            $favorite = $favorites->create(
                $this->user(),
                $title !== '' ? $title : $draft->title,
                $perServing,
                $portionScaler->scaleIngredients($ingredients, $scale),
                $title !== '' ? $title : $draft->title,
                $draft->imageWeb,
                $draft->tokens,
                $instructions !== '' ? $instructions : null,
                $servings,
                // The confirmed whole-batch list, verbatim — what a cook
                // follows; the scaled copy above is what meals log from.
                $ingredients !== [] ? $ingredients : null,
            );

            // The favorite copied the thumbnail; every staged photo can go.
            $this->drafts->clear($session, RecipeDraft::class);

            $this->addFlash('success', $this->translator->trans('recipe.imported', ['name' => $favorite->getName()]));

            return $this->redirectToRoute('app_favorites');
        }

        return $this->render('recipe/confirm.html.twig', [
            'state'    => $draft,
            'canRetry' => ClarifyRound::canContinue($draft->round),
        ]);
    }

    /**
     * A meal-style refinement round: the user's answers to the AI's questions
     * and their current ingredient edits go back to Claude with all the photos
     * for an updated estimate. The user's typed title/servings/instructions
     * win over the fresh AI values — they own those fields once touched.
     *
     */
    private function reanalyze(
        Request $request,
        SessionInterface $session,
        RecipeDraft $draft,
        RecipeAnalysisService $analyzer,
        string $title,
        float $servings,
        string $instructions,
    ): Response {
        if (!ClarifyRound::canContinue($draft->round)) {
            return $this->redirectToRoute('app_recipe_confirm');
        }

        $context = ClarifyRound::nextContext($draft->context, $draft->questions, $draft->ingredients, $request);

        try {
            $result = $analyzer->analyzeImages($this->user(), $draft->imagePaths, $context);
        } catch (PhotoLimitExceededException $e) {
            $this->addFlash('warning', $this->translator->trans($e->messageKey(), $e->messageParameters()));

            return $this->redirectToRoute($e->redirectRoute());
        } catch (\Throwable $e) {
            $this->logger->error('Recipe re-analysis failed: {error}', ['error' => $e->getMessage()]);
            $this->addFlash('error', $this->translator->trans('recipe.scan.failed'));

            return $this->redirectToRoute('app_recipe_confirm');
        }

        $this->drafts->store($session, $draft->afterRound($result, $context, $title, $servings, $instructions));

        return $this->redirectToRoute('app_recipe_confirm');
    }

    private function renderScan(mixed $form, AnalysisAllowance $allowance): Response
    {
        return $this->render('recipe/scan.html.twig', [
            'form'          => $form,
            'allowance' => $allowance->status($this->user()),
            'maxPhotos'     => RecipePhotoFormType::MAX_PHOTOS,
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
