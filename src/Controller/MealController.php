<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ConfidenceLevel;
use App\Dto\StagedPhoto;
use App\Draft\DraftSession;
use App\Draft\MealDraft;
use App\Draft\ReanalysisProposal;
use App\Draft\RetryParking;
use App\Entity\MealEntry;
use App\Entity\NutritionData;
use App\Entity\User;
use App\Enum\MealType;
use App\Enum\StagingPurpose;
use App\Exception\PhotoLimitExceededException;
use App\Exception\PhotoStagingFailed;
use App\Form\MealEditFormType;
use App\Form\MealEntryFormType;
use App\Form\MealReanalyzeFormType;
use App\Repository\MealEntryRepository;
use App\Service\AnalysisAllowance;
use App\Service\ClarifyRound;
use App\Service\ExifTimestampService;
use App\Service\FavoriteManager;
use App\Service\FoodAnalysisService;
use App\Service\MealDuplicateChecker;
use App\Service\NutritionDiff;
use App\Service\OnboardingService;
use App\Service\PhotoStaging;
use App\Service\PortionScaler;
use App\Service\GlycemicImpactEstimator;
use App\Service\PostprandialResponseService;
use App\Service\SubscriptionService;
use App\Service\UserTime;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/meal')]
class MealController extends AbstractController
{
    /** History page size — matches the admin user list's convention. */
    private const HISTORY_PER_PAGE = 25;

    public function __construct(
        private readonly string $uploadsDir,
        private readonly PortionScaler $portionScaler,
        private readonly UserTime $userTime,
        private readonly LoggerInterface $logger,
        private readonly MealDuplicateChecker $duplicateChecker,
        private readonly OnboardingService $onboarding,
        private readonly TranslatorInterface $translator,
        private readonly PhotoStaging $staging,
        private readonly DraftSession $drafts,
    ) {
    }

    #[Route('/log', name: 'app_meal_log', methods: ['GET', 'POST'])]
    public function log(
        Request $request,
        FoodAnalysisService $analyzer,
        AnalysisAllowance $allowance,
        ExifTimestampService $exifTimestamp,
        SessionInterface $session,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        $form = $this->createForm(MealEntryFormType::class);
        // Preselect the likely meal for the user's local time of day; a POST
        // overrides this via handleRequest as usual.
        $form->get('mealType')->setData(MealType::defaultForHour($this->userTime->localHour($user)));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile[] $imageFiles */
            $imageFiles = $form->get('image')->getData() ?? [];
            $describe = trim((string) $form->get('description')->getData());
            $notes = $form->get('notes')->getData();

            // Either path works: photo(s) — the dish, optionally with its
            // nutrition label — or a plain-text description (the photo-free path).
            if ($imageFiles === [] && $describe === '') {
                $form->get('image')->addError(
                    new FormError($this->translator->trans('meal.log.need_photo_or_text')),
                );

                return $this->renderLog($form, $session, $allowance);
            }

            // Stage every uploaded photo; the first is the meal's thumbnail, the
            // rest ride along as analysis extras.
            try {
                $staged = $this->staging->stage($imageFiles, StagingPurpose::MealPhoto);
            } catch (PhotoStagingFailed $e) {
                $this->logger->warning('Photo staging failed: {error}', ['error' => $e->getMessage()]);
                $this->addFlash('error', $e->invalidFileMessage() ?? $this->translator->trans('common.heic_failed'));

                return $this->renderLog($form, $session, $allowance);
            }
            $imagePaths = array_map(static fn(StagedPhoto $p): string => $p->path, $staged);
            $webPath = $staged !== [] ? $staged[0]->webPath : null;

            try {
                // A photo submission may carry a description too — fold it into
                // the notes so the first estimate already uses it (not only a
                // later re-analysis).
                $result = $imagePaths !== []
                    ? $analyzer->analyzeImage(
                        $this->getUser(),
                        $imagePaths,
                        [],
                        $this->combinedNotes($notes, $describe),
                    )
                    : $analyzer->analyzeDescription($this->getUser(), $describe, [], $notes);
            } catch (PhotoLimitExceededException $e) {
                // Free-tier monthly allowance reached — clean up the uploads and steer
                // the user to upgrade rather than showing a generic error.
                $this->staging->discard($staged);
                $this->addFlash('warning', $this->translator->trans('meal.log.limit_reached'));

                return $this->redirectToRoute('app_pricing');
            } catch (\Throwable $e) {
                // Log the detail (could be a transport error or raw model output);
                // show the user a generic message rather than leaking internals.
                $this->logger->error('Meal photo analysis failed: {error}', ['error' => $e->getMessage()]);
                $this->addFlash('error', $this->translator->trans('meal.log.analyze_failed'));

                if ($imagePaths === []) {
                    // Text-only failure: nothing on disk to park — the re-rendered
                    // form keeps the typed description, so retry is just resubmit.
                    return $this->renderLog($form, $session, $allowance);
                }

                // Keep the uploads so a transient failure costs one click, not a
                // re-upload — PRG so a refresh doesn't resubmit the form.
                $this->drafts->store($session, new RetryParking(
                    $imagePaths,
                    (string) $webPath,
                    $form->get('mealType')->getData()->value,
                    $notes,
                    $describe,
                ));

                return $this->redirectToRoute('app_meal_log');
            }

            // A fresh analysis succeeded — photos parked by an earlier failure
            // are no longer wanted.
            $this->drafts->clear($session, RetryParking::class);

            $loggedAt = $this->exifOrNow(
                $imagePaths !== [] ? $exifTimestamp->extractTimestamp($imagePaths[0]) : null,
            );

            // Every meal goes through the review screen so the user can adjust the
            // portion (and ingredients) before it's saved.
            $this->drafts->store($session, MealDraft::start(
                $imagePaths,
                $webPath,
                array_map(static fn(StagedPhoto $p): string => $p->webPath, array_slice($staged, 1)),
                $describe,
                $form->get('mealType')->getData()->value,
                $notes,
                $loggedAt,
                $result,
            ));

            return $this->redirectToRoute('app_meal_clarify');
        }

        return $this->renderLog($form, $session, $allowance);
    }

    /**
     * One-click re-run of a failed analysis using the photo kept on disk. The
     * meal type and notes from the original submission are preserved; success
     * proceeds to the normal Confirm & Refine review.
     */
    #[Route('/log/retry', name: 'app_meal_log_retry', methods: ['POST'])]
    public function logRetry(
        Request $request,
        FoodAnalysisService $analyzer,
        ExifTimestampService $exifTimestamp,
        SessionInterface $session,
    ): Response {
        if (!$this->isCsrfTokenValid('meal-retry', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_meal_log');
        }

        $retry = $this->drafts->load($session, RetryParking::class);
        if ($retry === null) {
            $this->addFlash('error', $this->translator->trans('meal.log.session_expired'));

            return $this->redirectToRoute('app_meal_log');
        }

        try {
            $result = $analyzer->analyzeImage(
                $this->getUser(),
                $retry->imagePaths,
                [],
                $this->combinedNotes($retry->notes, $retry->describe),
            );
        } catch (PhotoLimitExceededException $e) {
            $this->drafts->clear($session, RetryParking::class);
            $this->addFlash('warning', $this->translator->trans('meal.log.limit_reached'));

            return $this->redirectToRoute('app_pricing');
        } catch (\Throwable $e) {
            // Still failing — keep the photos parked for another attempt.
            $this->logger->error('Meal photo analysis failed: {error}', ['error' => $e->getMessage()]);
            $this->addFlash('error', $this->translator->trans('meal.log.analyze_failed'));

            return $this->redirectToRoute('app_meal_log');
        }

        $loggedAt = $this->exifOrNow($exifTimestamp->extractTimestamp($retry->imagePaths[0]));

        $this->drafts->store($session, MealDraft::start(
            $retry->imagePaths,
            $retry->imageWebPath,
            array_map(fn(string $p): string => $this->staging->webPath($p), array_slice($retry->imagePaths, 1)),
            $retry->describe,
            $retry->mealType,
            $retry->notes,
            $loggedAt,
            $result,
        ));
        // The parked photos now belong to the review; forget the parking without deleting them.
        $this->drafts->clear($session, RetryParking::class, keep: $retry->imagePaths);

        return $this->redirectToRoute('app_meal_clarify');
    }

    /**
     * The log page render, shared by every path that shows the form: carries the
     * pending retry card and the Free-tier allowance counter.
     */
    private function renderLog(
        FormInterface $form,
        SessionInterface $session,
        AnalysisAllowance $allowance,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $this->render('meal/log.html.twig', [
            'form'          => $form,
            'retry'         => $this->drafts->load($session, RetryParking::class),
            'freeRemaining' => $allowance->remaining($user),
            'freeLimit'     => AnalysisAllowance::FREE_MONTHLY_LIMIT,
        ]);
    }

    /**
     * Notes for a re-analysis: when a photo-free meal later gains a photo, the
     * user's original description is folded in so the analyzer keeps that
     * context; otherwise just the plain notes.
     *
     */
    private function combinedNotes(?string $notes, string $describe): ?string
    {
        $notes = trim((string) $notes);
        $describe = trim($describe);
        if ($describe === '') {
            return $notes !== '' ? $notes : null;
        }

        return trim('The user originally described this meal as: ' . $describe . "\n" . $notes);
    }

    #[Route('/clarify', name: 'app_meal_clarify', methods: ['GET', 'POST'])]
    public function clarify(
        Request $request,
        FoodAnalysisService $analyzer,
        EntityManagerInterface $em,
        SessionInterface $session,
        FavoriteManager $favorites,
    ): Response {
        // A reviewable draft needs either a photo or the described-meal text.
        $draft = $this->drafts->load($session, MealDraft::class);
        if ($draft === null) {
            return $this->redirectToRoute('app_meal_log');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('meal-clarify', (string) $request->request->get('_token'))) {
                $this->addFlash('error', $this->translator->trans('meal.log.session_expired'));

                return $this->redirectToRoute('app_meal_clarify');
            }

            // Saving keeps the ingredient table as the user left it, re-analysed
            // or not: a corrected name matters on its own (the inflammation
            // score matches on ingredient names). Nutrition stays as estimated,
            // the recipe import's posture.
            if ($request->request->get('accept') || $request->request->get('favorite')) {
                $draft = $draft->withIngredients(ClarifyRound::postedIngredients($request) ?? $draft->ingredients);
            }

            // "Save to log & favorite": remember the per-serving estimate, then
            // log the meal scaled by the portion multiplier (as a normal save).
            if ($request->request->get('favorite')) {
                $this->saveFavoriteFromState($favorites, $request, $draft);
            }

            // "Save": persist the current estimate scaled by the portion multiplier.
            // No re-analysis — what you reviewed is exactly what gets saved.
            if ($request->request->get('accept') || $request->request->get('favorite')) {
                $portions = $this->portionScaler->normalize($request->request->get('portions', 1));

                // Allow correcting when the meal was eaten (retroactive logging);
                // defaults to the EXIF/now value already in the session.
                $loggedAt = $this->parseLoggedAt((string) $request->request->get('logged_at', ''), $this->getUser());
                if ($loggedAt !== null) {
                    $draft = $draft->withLoggedAt($loggedAt);
                }

                return $this->saveFromState($em, $session, $draft, $portions);
            }

            // Otherwise: re-analyze with this round's answers + ingredient corrections —
            // unless the round cap is reached (the template hides the button; this
            // stops a stale or crafted form).
            if (!ClarifyRound::canContinue($draft->round)) {
                return $this->redirectToRoute('app_meal_clarify');
            }

            $context = ClarifyRound::nextContext($draft->context, $draft->questions, $draft->ingredients, $request);

            // An optional extra photo (e.g. the nutrition label Claude asked for).
            // A described meal has no photos unless one gets added here.
            $newExtraPath = null;
            $extraFile = $request->files->get('extra_photo');
            if ($extraFile instanceof UploadedFile) {
                try {
                    $extra = $this->staging->stage([$extraFile], StagingPurpose::MealExtra)[0];
                } catch (PhotoStagingFailed $e) {
                    $this->logger->warning('Photo staging failed: {error}', ['error' => $e->getMessage()]);
                    $this->addFlash(
                        'error',
                        $e->invalidFileMessage() ?? $this->translator->trans('common.heic_failed'),
                    );

                    return $this->redirectToRoute('app_meal_clarify');
                }
                $newExtraPath = $extra->path;
                $draft = $draft->withExtraPhoto($extra);
            }

            try {
                // Photo-free meals re-analyze from the description; one that later
                // gained a photo switches to the image path with the original
                // description folded into the notes so it isn't lost.
                $result = $draft->imagePaths !== []
                    ? $analyzer->analyzeImage(
                        $this->getUser(),
                        $draft->imagePaths,
                        $context,
                        $this->combinedNotes($draft->notes, $draft->describe),
                    )
                    : $analyzer->analyzeDescription($this->getUser(), $draft->describe, $context, $draft->notes);
            } catch (PhotoLimitExceededException $e) {
                // Allowance reached mid-refine — drop a just-stored extra photo and
                // send the user to upgrade.
                $this->staging->discard([$newExtraPath]);
                $this->addFlash('warning', $this->translator->trans('meal.log.limit_reached'));

                return $this->redirectToRoute('app_pricing');
            } catch (\Throwable $e) {
                // Don't orphan a just-stored extra photo when re-analysis fails —
                // the session isn't updated on this path, so nothing else tracks it.
                $this->staging->discard([$newExtraPath]);
                $this->logger->error('Meal re-analysis failed: {error}', ['error' => $e->getMessage()]);
                $this->addFlash('error', $this->translator->trans('meal.log.reanalyze_failed'));

                return $this->redirectToRoute('app_meal_clarify');
            }

            $this->drafts->store($session, $draft->afterRound($context, $result));

            return $this->redirectToRoute('app_meal_clarify');
        }

        // GET — show the estimate, ingredients, questions, and portion control.
        $user = $this->getUser();
        \assert($user instanceof User);

        return $this->render('meal/clarify.html.twig', [
            'state'           => $draft,
            'nowLocal'        => $this->userTime->now($user),
            'glp1Mode'        => $user->isGlp1Mode(),
            'round'           => $draft->round,
            'canReanalyze'    => ClarifyRound::canContinue($draft->round),
            'description'     => $draft->description,
            'questions'       => $draft->questions,
            'ingredients'     => $draft->ingredients,
            'nutrition'       => $draft->nutrition,
            'extraPhotos'     => $draft->extraWebPaths,
            'confidence'      => new ConfidenceLevel((float) ($draft->nutrition['confidenceScore'] ?? 0.0)),
            'favoriteDefault' => FavoriteManager::defaultName($draft->description, $draft->ingredients),
        ]);
    }

    /**
     * Saves a favorite from the reviewed estimate — the un-scaled, per-serving
     * nutrition and ingredients, plus the analysis's token cost so each later
     * reuse can be credited.
     */
    private function saveFavoriteFromState(
        FavoriteManager $favorites,
        Request $request,
        MealDraft $draft,
    ): void {
        /** @var User $user */
        $user = $this->getUser();

        $favorite = $favorites->create(
            $user,
            (string) $request->request->get('favorite_name', ''),
            $draft->nutrition,
            $draft->ingredients,
            $draft->description,
            $draft->imageWebPath,
            $draft->analysisTokens,
        );

        $this->addFlash('success', $this->translator->trans(
            'meal.log.favorite_saved',
            ['name' => $favorite->getName()],
        ));
    }

    /**
     * Meal history: every logged meal, newest first, with optional date-range +
     * meal-type filters and Prev/Next pagination (the admin user list's idiom —
     * plain GET params, bookmarkable, filters carried across page links).
     */
    #[Route('/history', name: 'app_meal_history', methods: ['GET'])]
    public function history(Request $request, MealEntryRepository $repo): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        // Junk filter values are ignored, not errors (same posture as the admin list).
        $type = MealType::tryFrom((string) $request->query->get('type', ''));
        $from = $this->parseFilterDate((string) $request->query->get('from', ''));
        $to   = $this->parseFilterDate((string) $request->query->get('to', ''));
        // The "to" day is inclusive: compare against the start of the next day.
        $toExclusive = $to?->modify('+1 day');

        $total = $repo->countHistory($user, $from, $toExclusive, $type);
        $pages = max(1, (int) ceil($total / self::HISTORY_PER_PAGE));
        $page  = min(max(1, $request->query->getInt('page', 1)), $pages);
        $meals = $repo->historyPage($user, $from, $toExclusive, $type, $page, self::HISTORY_PER_PAGE);

        // Only the filters that are actually set ride along on pagination links.
        $filterParams = array_filter([
            'type' => $type?->value,
            'from' => $from?->format('Y-m-d'),
            'to'   => $to?->format('Y-m-d'),
        ]);

        return $this->render('meal/history.html.twig', [
            'meals'         => $meals,
            'mealTypes'     => MealType::cases(),
            'page'          => $page,
            'pages'         => $pages,
            'total'         => $total,
            'perPage'       => self::HISTORY_PER_PAGE,
            'filterParams'  => $filterParams,
            'hasFilters'    => $filterParams !== [],
            'typeFilter'    => $type,
            'fromValue'     => $from?->format('Y-m-d') ?? '',
            'toValue'       => $to?->format('Y-m-d') ?? '',
        ]);
    }

    /**
     * Phases 1+2 of the meal-history plan: type/notes/time (Phase 1) plus
     * manual nutrient corrections, a portion rescale, and cross-day moves
     * behind a confirmation (Phase 2). Everything downstream (dashboard,
     * trends, inflammation, correlation, goals, Predict) reads meal rows
     * live, so these are plain field updates — no recompute step exists or is
     * needed. Nutrient/portion edits stamp nutritionEditedAt (provenance:
     * user-corrected, no longer the AI estimate); any effective edit stamps
     * editedAt (the "edited on" trust marker). Photos/re-analysis are Phase 3.
     */
    #[Route('/{id}/edit', name: 'app_meal_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, MealEntryRepository $repo, EntityManagerInterface $em): Response
    {
        $entry = $repo->find($id);

        if (!$entry || $entry->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        $user = $this->getUser();
        \assert($user instanceof User);

        $nutrition    = $entry->getNutritionData();
        $originalDay  = $entry->getLoggedAt()->format('Y-m-d');
        $originalType = $entry->getMealType();
        $originalNotes = $entry->getNotes();

        // DateTimeType's string model format is Y-m-d H:i:s (the html5 widget
        // renders/reads Y-m-d\TH:i, so seconds round to :00 on save). The
        // nutrient/portion fields exist only when there IS a NutritionData row
        // to edit (include_nutrition — see the form type).
        $form = $this->createForm(MealEditFormType::class, $entry, [
            'include_nutrition' => $nutrition !== null,
        ]);
        $form->get('logged_at')->setData($entry->getLoggedAt()->format('Y-m-d H:i:s'));
        if ($nutrition !== null) {
            foreach (MealEditFormType::NUTRIENT_FIELDS as $field) {
                $form->get($field)->setData($nutrition->{'get' . ucfirst($field)}());
            }
        }
        $form->handleRequest($request);

        // Drives the template's day-change confirmation block on a re-render
        // (the inline JS reveal only covers the pre-submit path).
        $dayChangeNeeded = false;

        if ($form->isSubmitted() && $form->isValid()) {
            // Re-parse the posted wall-clock in the user's zone (the
            // parseLoggedAt idiom) so the future-guard comparison is
            // instant-correct and the stored value stays user wall-clock.
            $newLoggedAt = \DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                (string) $form->get('logged_at')->getData(),
                $this->userTime->zone($user),
            );

            if ($newLoggedAt === false || $newLoggedAt > $this->userTime->now($user)) {
                $form->get('logged_at')->addError(new FormError($this->translator->trans('meal.edit.future_time')));
            } elseif (
                $newLoggedAt->format('Y-m-d') !== $originalDay
                && $form->get('confirm_day_change')->getData() !== true
            ) {
                // The plan's one deliberate speed bump: a cross-day move
                // silently rewrites both days' totals/scores/charts, so it
                // must be confirmed, never a byproduct of a typo in the date.
                $dayChangeNeeded = true;
                $form->get('confirm_day_change')->addError(
                    new FormError($this->translator->trans('meal.edit.day_change_required')),
                );
            } else {
                $changed = $originalType !== $entry->getMealType() || $originalNotes !== $entry->getNotes();

                if ($newLoggedAt->format('Y-m-d H:i:s') !== $entry->getLoggedAt()->format('Y-m-d H:i:s')) {
                    $entry->setLoggedAt($newLoggedAt);
                    $changed = true;
                }

                // Manual nutrient corrections: blank keeps the stored value
                // (never nulls it); only a genuinely different number counts
                // as an edit, so re-saving the prefilled form is a no-op.
                $nutritionChanged = false;
                if ($nutrition !== null) {
                    foreach (MealEditFormType::NUTRIENT_FIELDS as $field) {
                        $value = $form->get($field)->getData();
                        if ($value === null) {
                            continue;
                        }
                        $current = $nutrition->{'get' . ucfirst($field)}();
                        if ($current === null || abs($current - (float) $value) > 1e-9) {
                            $nutrition->{'set' . ucfirst($field)}((float) $value);
                            $nutritionChanged = true;
                        }
                    }

                    // One-shot portion rescale over the (possibly just
                    // corrected) numbers + numeric ingredient amounts — the
                    // same math as the log flow, applied in place.
                    $portions = $form->get('portions')->getData();
                    if ($portions !== null) {
                        $scale = $this->portionScaler->normalize($portions);
                        if ($scale !== 1.0) {
                            $nutrition->scaleBy($scale);
                            $entry->setIngredients(
                                $this->portionScaler->scaleIngredients($entry->getIngredients(), $scale),
                            );
                            $nutritionChanged = true;
                        }
                    }
                }

                if ($nutritionChanged) {
                    $entry->setNutritionEditedAt($this->userTime->now($user));
                    // ⚠️ And drop the re-analysis stamp: the two are mutually
                    // exclusive answers to "where did these numbers come from",
                    // so a hand-corrected meal must stop claiming its figures
                    // are a fresh AI estimate. (Applying a re-analysis clears
                    // nutritionEditedAt for the same reason — see
                    // reanalyzeReview's applyReanalysis.)
                    $entry->setReanalyzedAt(null);
                    $changed = true;
                }
                if ($changed) {
                    $entry->setEditedAt($this->userTime->now($user));
                }

                $em->flush();
                $this->addFlash('success', $this->translator->trans('meal.edit.saved'));

                return $this->redirectToRoute('app_meal_show', ['id' => $entry->getId()]);
            }
        }

        return $this->render('meal/edit.html.twig', [
            'entry'           => $entry,
            'form'            => $form,
            'originalDay'     => $originalDay,
            'dayChangeNeeded' => $dayChangeNeeded,
        ]);
    }

    /**
     * Phase 3, step 1 of the meal-history plan: buy ONE Claude call to
     * re-estimate an already-logged meal from a new/replacement photo, an
     * edited description, or both. Nothing is written to the meal here — the
     * result is parked for the review screen, because the plan's §4 rule is
     * that a re-analysis must never silently overwrite a number the user
     * corrected by hand.
     */
    #[Route('/{id}/reanalyze', name: 'app_meal_reanalyze', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function reanalyze(
        int $id,
        Request $request,
        MealEntryRepository $repo,
        FoodAnalysisService $analyzer,
        AnalysisAllowance $allowance,
        SessionInterface $session,
    ): Response {
        $entry = $repo->find($id);

        if (!$entry || $entry->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        $user = $this->getUser();
        \assert($user instanceof User);

        // The meal's own photo, only if it's actually on disk — a missing file
        // must not count as an analyzable image (analyzeImage would filter it
        // and then throw on an empty list).
        $existingPath = $this->mealPhotoPath($entry);

        $form = $this->createForm(MealReanalyzeFormType::class, null, [
            'has_photo' => $existingPath !== null,
        ]);
        // The description the AI will read: the user's own notes if they wrote
        // any, else the AI's own description as a starting point to correct.
        // (A photo-free meal's original wording isn't stored anywhere — the log
        // flow folds it into the prompt only — so this field is also how that
        // text finally becomes persistent, via the notes on apply.)
        $form->get('description')->setData($entry->getNotes() ?: $entry->getAiDescription());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile[] $imageFiles */
            $imageFiles  = $form->get('image')->getData() ?? [];
            $description = trim((string) $form->get('description')->getData());
            // A "replace the photo" tick with nothing uploaded has nothing to
            // replace it with, so it's ignored rather than treated as a request
            // to drop the existing photo from the analysis.
            $replacePhoto = $existingPath === null || (
                $imageFiles !== []
                && $form->has('replace_photo')
                && $form->get('replace_photo')->getData() === true
            );

            if ($imageFiles === [] && $existingPath === null && $description === '') {
                $form->get('description')->addError(
                    new FormError($this->translator->trans('meal.reanalyze.need_input')),
                );

                return $this->renderReanalyze($entry, $form, $allowance);
            }

            try {
                $newPaths = array_map(
                    static fn(StagedPhoto $p): string => $p->path,
                    $this->staging->stage($imageFiles, StagingPurpose::MealReanalysis),
                );
            } catch (PhotoStagingFailed $e) {
                $this->logger->warning('Photo staging failed: {error}', ['error' => $e->getMessage()]);
                $this->addFlash('error', $e->invalidFileMessage() ?? $this->translator->trans('common.heic_failed'));

                return $this->renderReanalyze($entry, $form, $allowance);
            }

            // New photos first — they carry the newest information — then the
            // meal's own, when it's being kept. Sliced to the log form's
            // per-meal photo budget so one Claude request can't grow unbounded;
            // a photo dropped from the ANALYSIS is still kept on disk and can
            // still become the thumbnail.
            $paths = array_slice(
                $replacePhoto ? $newPaths : array_merge($newPaths, array_filter([$existingPath])),
                0,
                MealEntryFormType::MAX_PHOTOS,
            );

            try {
                // Passing the meal books the call on the ledger: a re-analysis
                // leaves no countable row of its own, so the ledger entry is the
                // only thing that makes it draw on the monthly allowance. It is
                // booked only once the analysis succeeds.
                $result = $paths !== []
                    ? $analyzer->analyzeImage($user, $paths, [], $description !== '' ? $description : null, $entry)
                    : $analyzer->analyzeDescription($user, $description, reanalysisOf: $entry);
            } catch (PhotoLimitExceededException $e) {
                $this->staging->discard($newPaths);
                $this->addFlash('warning', $this->translator->trans('meal.log.limit_reached'));

                return $this->redirectToRoute('app_pricing');
            } catch (\Throwable $e) {
                $this->staging->discard($newPaths);
                $this->logger->error('Meal re-analysis failed: {error}', ['error' => $e->getMessage()]);
                $this->addFlash('error', $this->translator->trans('meal.log.reanalyze_failed'));

                return $this->redirectToRoute('app_meal_reanalyze', ['id' => $entry->getId()]);
            }

            // Parking a proposal drops the photos of any earlier abandoned one
            // (possibly another meal's), so they can't pile up as orphans.
            $this->drafts->store($session, ReanalysisProposal::fromAnalysis(
                (int) $entry->getId(),
                $description,
                $newPaths,
                $replacePhoto,
                $result,
            ));

            return $this->redirectToRoute('app_meal_reanalyze_review', ['id' => $entry->getId()]);
        }

        return $this->renderReanalyze($entry, $form, $allowance);
    }

    /**
     * Phase 3, step 2: the diff. The proposed estimate is shown against the
     * stored one and applied only on an explicit Apply — and when the meal
     * carries a manual nutrition correction, only behind a second confirmation
     * (the plan's §4 rule, given the same server-enforced speed bump as a
     * cross-day move).
     */
    #[Route(
        '/{id}/reanalyze/review',
        name: 'app_meal_reanalyze_review',
        requirements: ['id' => '\d+'],
        methods: ['GET', 'POST'],
    )]
    public function reanalyzeReview(
        int $id,
        Request $request,
        MealEntryRepository $repo,
        EntityManagerInterface $em,
        NutritionDiff $diff,
        SessionInterface $session,
    ): Response {
        $entry = $repo->find($id);

        if (!$entry || $entry->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        $user = $this->getUser();
        \assert($user instanceof User);

        // Guard the meal id, not just the presence of state: a proposal parked
        // for one meal must never be applied to another.
        $proposal = $this->drafts->load($session, ReanalysisProposal::class);
        if ($proposal === null || !$proposal->isFor((int) $entry->getId())) {
            $this->addFlash('error', $this->translator->trans('meal.log.session_expired'));

            return $this->redirectToRoute('app_meal_reanalyze', ['id' => $entry->getId()]);
        }

        // ⚠️ A parked proposal can outlive its uploaded files: the session may
        // survive longer than app:cleanup-meal-uploads' unreferenced-and-old
        // threshold, and those files are unreferenced by definition until they
        // are applied. Dropping the vanished ones here is what stops apply from
        // pointing the meal at a missing file AND deleting its previous, valid
        // photo on the way — losing the real photo to replace it with nothing.
        // The estimate itself is still good (and already paid for), so only the
        // photo half is abandoned, and the user is told.
        $proposal = $this->pruneMissingParkedPhotos($session, $proposal);

        $overwriteNeeded = false;

        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_token');
            if (!$this->isCsrfTokenValid('meal-reanalyze-' . $entry->getId(), $token)) {
                $this->addFlash('error', $this->translator->trans('meal.log.session_expired'));

                return $this->redirectToRoute('app_meal_reanalyze_review', ['id' => $entry->getId()]);
            }

            if ($request->request->get('discard')) {
                $this->drafts->clear($session, ReanalysisProposal::class);
                $this->addFlash('success', $this->translator->trans('meal.reanalyze.discarded'));

                return $this->redirectToRoute('app_meal_show', ['id' => $entry->getId()]);
            }

            // The plan's §4 rule: hand-corrected numbers are known-good to the
            // person who typed them, so replacing them takes a deliberate second
            // action rather than one Apply click.
            if (
                $entry->getNutritionEditedAt() !== null
                && $request->request->get('confirm_overwrite') !== '1'
            ) {
                $overwriteNeeded = true;
                $this->addFlash('error', $this->translator->trans('meal.reanalyze.overwrite_required'));
            } else {
                $this->applyReanalysis($em, $entry, $proposal, $user);
                $this->drafts->clear($session, ReanalysisProposal::class, keep: [$proposal->appliedPhoto()]);
                $this->addFlash('success', $this->translator->trans('meal.reanalyze.applied'));

                return $this->redirectToRoute('app_meal_show', ['id' => $entry->getId()]);
            }
        }

        $rows = $diff->rows($entry->getNutritionData(), $proposal->nutrition);

        return $this->render('meal/reanalyze_review.html.twig', [
            'entry'            => $entry,
            'rows'             => $rows,
            'hasChanges'       => $diff->hasChanges($rows),
            'state'            => $proposal,
            'newPhotos'        => array_map(
                fn(string $p): string => $this->staging->webPath($p),
                $proposal->newPaths,
            ),
            'currentConfidence' => $entry->getNutritionData()?->getConfidenceScore(),
            'proposedConfidence' => $proposal->nutrition['confidenceScore'] ?? null,
            'overwriteNeeded'  => $overwriteNeeded,
        ], new Response(status: $overwriteNeeded ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * Writes an approved proposal onto the meal. Everything downstream reads
     * meal rows live, so like the Phase-2 edits this is a plain field update
     * with no recompute step.
     */
    private function applyReanalysis(
        EntityManagerInterface $em,
        MealEntry $entry,
        ReanalysisProposal $proposal,
        User $user,
    ): void {
        $nutrition = $entry->getNutritionData();
        if ($nutrition === null) {
            // A meal logged without a nutrition row gains one — the re-analysis
            // is the first estimate it has ever had.
            $entry->setNutritionData(NutritionData::fromArray($proposal->nutrition));
        } else {
            $nutrition->applyArray($proposal->nutrition);
        }

        $entry->setIngredients($proposal->ingredients);
        $entry->setAiDescription($proposal->aiDescription);

        // The submitted description becomes the meal's notes, so the text that
        // produced this estimate is the text the next re-analysis starts from.
        if (trim($proposal->description) !== '') {
            $entry->setNotes(trim($proposal->description));
        }

        // The is_file guard is deliberately redundant with
        // pruneMissingParkedPhotos: this is the one place that DELETES the
        // meal's existing photo, so it must never fire on the strength of a
        // path alone.
        $applied = $proposal->appliedPhoto();
        if ($applied !== null && is_file($applied)) {
            $previous = $this->mealPhotoPath($entry);
            $entry->setImagePath($this->staging->webPath($applied));
            if ($previous !== null && $previous !== $applied) {
                unlink($previous);
            }
        }

        $now = $this->userTime->now($user);
        $entry->setReanalyzedAt($now);
        // The numbers are a fresh AI estimate again, so the manual-correction
        // provenance no longer describes them (and the confidence bar, which it
        // suppresses, is meaningful once more).
        $entry->setNutritionEditedAt(null);
        $entry->setEditedAt($now);

        $em->flush();
    }

    /**
     * Drops parked upload paths whose files no longer exist, re-storing the
     * proposal so both the review render and a later apply see the same truth,
     * and tells the user when that happened.
     */
    private function pruneMissingParkedPhotos(
        SessionInterface $session,
        ReanalysisProposal $proposal,
    ): ReanalysisProposal {
        $pruned = $proposal->withoutMissingPhotos();
        $gone = count($proposal->newPaths) - count($pruned->newPaths);
        if ($gone === 0) {
            return $proposal;
        }

        $this->drafts->store($session, $pruned);
        $this->logger->warning('Parked re-analysis photo(s) vanished before apply: {gone} of {total}', [
            'gone'  => $gone,
            'total' => count($proposal->newPaths),
        ]);
        $this->addFlash('warning', $this->translator->trans('meal.reanalyze.photos_expired'));

        return $pruned;
    }

    /** The meal photo's server-side path, or null when there isn't one on disk. */
    private function mealPhotoPath(MealEntry $entry): ?string
    {
        $webPath = $entry->getImagePath();
        if ($webPath === null || $webPath === '') {
            return null;
        }

        $path = $this->uploadsDir . '/' . basename($webPath);

        return is_file($path) ? $path : null;
    }

    /**
     * The re-analysis form render, shared by the GET and every re-render, so
     * the allowance counter can't go missing on an error path.
     */
    private function renderReanalyze(
        MealEntry $entry,
        FormInterface $form,
        AnalysisAllowance $allowance,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $this->render('meal/reanalyze.html.twig', [
            'entry'         => $entry,
            'form'          => $form,
            'freeRemaining' => $allowance->remaining($user),
            'freeLimit'     => AnalysisAllowance::FREE_MONTHLY_LIMIT,
        ], new Response(status: $form->isSubmitted() && !$form->isValid()
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK));
    }

    /**
     * Parses a Y-m-d history filter value; junk → null (filter ignored). The
     * round-trip check matters: createFromFormat() normalizes impossible dates
     * (2026-02-31 → 2026-03-03) instead of failing, which would silently
     * filter the wrong day.
     */
    private function parseFilterDate(string $raw): ?\DateTimeImmutable
    {
        if ($raw === '') {
            return null;
        }

        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        return ($dt !== false && $dt->format('Y-m-d') === $raw) ? $dt : null;
    }

    #[Route('/{id}', name: 'app_meal_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, MealEntryRepository $repo, PostprandialResponseService $glucose, GlycemicImpactEstimator $estimator, SubscriptionService $subscriptions): Response // phpcs:ignore Generic.Files.LineLength
    {
        $entry = $repo->find($id);

        if (!$entry || $entry->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        // The CGM↔meal glucose card is a Loop+ feature. Compute the response once; if
        // the user isn't entitled but there *is* glucose data near this meal, show an
        // upgrade prompt in place of the card instead of the data.
        $response   = $glucose->forMeal($entry);
        $cgmEnabled = $subscriptions->hasFeature($this->getUser(), 'cgm_correlation');
        $glucoseResponse = $cgmEnabled ? $response : null;
        $nutrition       = $entry->getNutritionData();

        return $this->render('meal/show.html.twig', [
            'entry'           => $entry,
            'glucoseResponse' => $glucoseResponse,
            // Locked card when glucose data exists but the user lacks the entitlement.
            'cgmLocked'       => !$cgmEnabled && $response !== null,
            // Only meaningful (and only rendered) when there's a response curve, so
            // skip the estimate otherwise — matches what the template can display.
            'glycemicImpact'  => ($glucoseResponse !== null && $glucoseResponse->hasCurve && $nutrition !== null)
                ? $estimator->estimate($nutrition, $glucoseResponse)
                : null,
            'favoriteDefault' => FavoriteManager::defaultName(
                $entry->getAiDescription(),
                $entry->getIngredients(),
            ),
        ]);
    }

    #[Route('/{id}/delete', name: 'app_meal_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, MealEntryRepository $repo, EntityManagerInterface $em, Request $request): Response
    {
        $entry = $repo->find($id);

        if (!$entry || $entry->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        if ($this->isCsrfTokenValid('delete-meal-' . $id, $request->request->get('_token'))) {
            $imagePath = $this->uploadsDir . '/' . basename($entry->getImagePath() ?? '');
            if ($entry->getImagePath() && file_exists($imagePath)) {
                unlink($imagePath);
            }
            $em->remove($entry);
            $em->flush();
            $this->addFlash('success', $this->translator->trans('meal.log.deleted'));
        }

        return $this->redirectToRoute('app_dashboard');
    }

    /**
     * Parses the posted "when did you eat this" value (HTML datetime-local).
     * The value is the user's local wall-clock, so it's interpreted in their
     * timezone — that's also the frame the future-guard compares against.
     * Returns null — keeping the EXIF/now default — when empty, unparseable, or
     * in the future.
     */
    /**
     * The default "when eaten" for a new photo meal: the photo's EXIF time
     * (deliberate — it lets a batch of older photos log onto their own days),
     * else now. A FUTURE EXIF time — a camera clock set ahead, or a wrong zone —
     * falls back to now: parseLoggedAt() rejects a future time on save, so the
     * bad default would otherwise stick. EXIF carries no zone (its digits are
     * the camera's wall-clock), so the check compares wall-clock strings.
     */
    private function exifOrNow(?\DateTimeImmutable $exif): \DateTimeImmutable
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        $now = $this->userTime->now($user);

        if ($exif === null || $exif->format('Y-m-d H:i:s') > $now->format('Y-m-d H:i:s')) {
            return $now;
        }

        return $exif;
    }

    private function parseLoggedAt(string $raw, User $user): ?\DateTimeImmutable
    {
        if ($raw === '') {
            return null;
        }

        $zone = $this->userTime->zone($user);
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $raw, $zone)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $raw, $zone);

        if ($dt === false || $dt > $this->userTime->now($user)) {
            return null;
        }

        return $dt;
    }

    /**
     * Logs the meal from the reviewed draft, scaling nutrition (and numeric
     * ingredient amounts) by the portion multiplier.
     */
    private function saveFromState(
        EntityManagerInterface $em,
        SessionInterface $session,
        MealDraft $draft,
        float $portions,
    ): Response {
        $loggedAt = $draft->loggedAt;

        $user = $this->getUser();
        \assert($user instanceof User);

        // Check before persisting (so the new meal isn't compared against itself):
        // the same photo at the same date/time is almost certainly a duplicate from
        // re-saving a reused photo. We warn but don't block — the service logs it too.
        $isDuplicate = $this->duplicateChecker->isDuplicate($user, $loggedAt, $draft->imageWebPath);

        $entry = new MealEntry();
        $entry->setUser($user);
        $entry->setMealType(MealType::from($draft->mealType));
        $entry->setImagePath($draft->imageWebPath);
        $entry->setNotes($draft->notes);
        $entry->setLoggedAt($loggedAt);
        $entry->setAiDescription($draft->description);
        $entry->setIngredients($this->portionScaler->scaleIngredients($draft->ingredients, $portions));
        $entry->setNutritionData(NutritionData::fromArray($draft->nutrition, $portions));

        $em->persist($entry);
        $em->flush();

        // The very first meal (in the onboarding sense) gets a one-time success
        // callout on the meal page — decided BEFORE markStep stamps the timestamp.
        $isFirstMeal = $user->needsOnboarding() && $user->getOnboardingMealAt() === null;

        // First meal logged → advance onboarding step 1 (no-op once onboarding is done).
        $this->onboarding->markStep($user, 'meal');

        // Extra photos (nutrition labels etc.) were only an analysis aid — the
        // food photo is the meal's thumbnail, so only it is kept.
        $this->drafts->clear($session, MealDraft::class, keep: [$draft->imagePath]);

        if ($isFirstMeal) {
            // Rendered as a celebratory callout on the meal page
            // (templates/meal/show.html.twig) — the payoff for completing the
            // hardest onboarding step shouldn't be just a generic saved flash. The
            // flash value tells the template whether the meal landed on the user's
            // local today: a backdated first meal (EXIF default / edited date) puts
            // nothing on today's dashboard, so promising "today's score" would
            // point at a payoff that isn't there.
            $isToday = $loggedAt->format('Y-m-d') === $this->userTime->now($user)->format('Y-m-d');
            $this->addFlash('first_meal', $isToday ? 'today' : 'backdated');
        }

        if ($isDuplicate) {
            // Surfaced as a rich callout with a "Delete Entry" action on the meal page
            // (templates/meal/show.html.twig), not the generic warning bag — so the user
            // is told exactly what to do if the duplicate wasn't intended.
            $this->addFlash('meal_duplicate', '1');
        }

        $this->addFlash('success', $portions === 1.0
            ? $this->translator->trans('meal.log.saved')
            : $this->translator->trans('meal.log.saved_portions', [
                'count' => rtrim(rtrim(number_format($portions, 2), '0'), '.'),
            ]));

        return $this->redirectToRoute('app_meal_show', ['id' => $entry->getId()]);
    }
}
