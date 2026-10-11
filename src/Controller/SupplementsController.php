<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\StagedPhoto;
use App\Draft\DraftSession;
use App\Draft\SupplementDraft;
use App\Entity\Supplement;
use App\Entity\User;
use App\Enum\StagingPurpose;
use App\Exception\PhotoLimitExceededException;
use App\Exception\PhotoStagingFailed;
use App\Form\SupplementPhotoFormType;
use App\Intake\DailyIntake;
use App\Repository\SupplementRepository;
use App\Service\AnalysisAllowance;
use App\Service\PhotoStaging;
use App\Service\SupplementAnalysisService;
use App\Service\SupplementRecommendationService;
use App\Service\UserTime;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class SupplementsController extends AbstractController
{
    public function __construct(
        private readonly string $supplementsDir,
        private readonly LoggerInterface $logger,
        private readonly PhotoStaging $staging,
        private readonly DraftSession $drafts,
    ) {
    }

    #[Route('/supplements', name: 'app_supplements', methods: ['GET'])]
    public function index(
        Request $request,
        SupplementRecommendationService $service,
        SupplementRepository $supplements,
        DailyIntake $intake,
        UserTime $userTime,
    ): Response {
        $days = (int) $request->query->get('days', 7);
        // Same window options as the Insights page, so its Micronutrients tile can
        // link here with the exact period the user selected (incl. 90).
        $days = in_array($days, [7, 14, 30, 90], true) ? $days : 7;

        // The average intake is fetched here so the template can tell "no gaps"
        // from "no meals in this window" via hasNutritionData: an empty gap list
        // alone is ambiguous.
        $user = $this->getUser();
        \assert($user instanceof User);
        $averageIntake = $intake->average(
            $user,
            $userTime->startOfDaysAgo($user, $days),
            $userTime->startOfTomorrow($user),
        )?->totals() ?? [];

        return $this->render('supplements/index.html.twig', [
            'recommendations' => $service->computeGaps($averageIntake),
            'hasWindowData'   => $service->hasNutritionData($averageIntake),
            'supplements'     => $supplements->findByUserOrdered($this->getUser()),
            'days'            => $days,
        ]);
    }

    #[Route('/supplements/scan', name: 'app_supplement_scan', methods: ['GET', 'POST'])]
    public function scan(
        Request $request,
        SupplementAnalysisService $analyzer,
        SessionInterface $session,
        TranslatorInterface $translator,
        AnalysisAllowance $allowance,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);
        $form = $this->createForm(SupplementPhotoFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile[] $imageFiles */
            $imageFiles = $form->get('images')->getData();

            try {
                $staged = $this->staging->stage($imageFiles, StagingPurpose::Supplement);
            } catch (PhotoStagingFailed $e) {
                $this->logger->warning('Photo staging failed: {error}', ['error' => $e->getMessage()]);
                $this->addFlash('error', $e->invalidFileMessage() ?? $translator->trans('common.heic_failed'));

                return $this->renderScan($form, $allowance, $user);
            }
            $paths = array_map(static fn(StagedPhoto $p): string => $p->path, $staged);

            try {
                $result = $analyzer->analyzeImages($user, $paths);
            } catch (PhotoLimitExceededException) {
                // Free monthly allowance used up — same destination as the meal and
                // recipe flows.
                $this->staging->discard($staged);
                $this->addFlash('warning', $translator->trans('meal.log.limit_reached'));

                return $this->redirectToRoute('app_pricing');
            } catch (\Throwable $e) {
                $this->staging->discard($staged);
                $this->logger->error('Supplement label analysis failed: {error}', ['error' => $e->getMessage()]);
                $this->addFlash('error', $translator->trans('supplement.scan.failed'));

                return $this->renderScan($form, $allowance, $user);
            }

            // Keep only the first photo as the thumbnail; the rest were just for reading.
            $this->staging->discard(array_slice($staged, 1));

            $this->drafts->store($session, SupplementDraft::fromScan($result, $staged[0] ?? null));

            return $this->redirectToRoute('app_supplement_confirm');
        }

        return $this->renderScan($form, $allowance, $user);
    }

    private function renderScan(mixed $form, AnalysisAllowance $allowance, User $user): Response
    {
        return $this->render('supplements/scan.html.twig', [
            'form'          => $form,
            'freeRemaining' => $allowance->remaining($user),
            'freeLimit'     => AnalysisAllowance::FREE_MONTHLY_LIMIT,
        ]);
    }

    #[Route('/supplements/confirm', name: 'app_supplement_confirm', methods: ['GET', 'POST'])]
    public function confirm(
        Request $request,
        EntityManagerInterface $em,
        SessionInterface $session,
        SupplementRecommendationService $recommendations,
        TranslatorInterface $translator,
    ): Response {
        $draft = $this->drafts->load($session, SupplementDraft::class);
        if ($draft === null) {
            return $this->redirectToRoute('app_supplement_scan');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('supplement-confirm', (string) $request->request->get('_token'))) {
                return $this->redirectToRoute('app_supplement_confirm');
            }

            $nutrients = [];
            $captureKeys = array_merge(
                array_keys($recommendations->macroMeta()),
                array_keys($recommendations->nutrientMeta()),
            );
            foreach ($captureKeys as $key) {
                $raw = trim((string) $request->request->get('nutrient_' . $key, ''));
                if ($raw !== '' && is_numeric($raw) && (float) $raw > 0) {
                    $nutrients[$key] = (float) $raw;
                }
            }

            $supplement = new Supplement();
            $supplement->setUser($this->getUser());
            $supplement->setName(trim((string) $request->request->get('name')) ?: 'Supplement');
            $supplement->setServingsPerDay($this->servingsPerDay($request));
            $supplement->setNutrients($nutrients);
            // The scan's photo was staged with the other drafts' (and is swept
            // if the scan is abandoned); saving moves it to its own directory.
            $supplement->setImagePath($draft->photo !== null
                ? $this->staging->keep($this->staging->path($draft->photo->webPath), 'uploads/supplements')
                : null);

            $em->persist($supplement);
            $em->flush();
            $this->drafts->clear($session, SupplementDraft::class);

            $this->addFlash('success', $translator->trans('supplement.added', ['name' => $supplement->getName()]));

            return $this->redirectToRoute('app_supplements');
        }

        return $this->render('supplements/confirm.html.twig', [
            'state'        => $draft,
            'macroMeta'    => $recommendations->macroMeta(),
            'nutrientMeta' => $recommendations->nutrientMeta(),
        ]);
    }

    #[Route(
        '/supplements/{id}/toggle',
        name: 'app_supplement_toggle',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function toggle(int $id, Request $request, SupplementRepository $repo, EntityManagerInterface $em): Response
    {
        $supplement = $this->ownedSupplement($id, $repo);

        if ($this->isCsrfTokenValid('supplement-toggle-' . $id, (string) $request->request->get('_token'))) {
            $supplement->setActive(!$supplement->isActive());
            $em->flush();
        }

        return $this->redirectToRoute('app_supplements');
    }

    #[Route(
        '/supplements/{id}/update',
        name: 'app_supplement_update',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function update(int $id, Request $request, SupplementRepository $repo, EntityManagerInterface $em): Response
    {
        $supplement = $this->ownedSupplement($id, $repo);

        if ($this->isCsrfTokenValid('supplement-update-' . $id, (string) $request->request->get('_token'))) {
            $supplement->setServingsPerDay($this->servingsPerDay($request));
            $em->flush();
        }

        return $this->redirectToRoute('app_supplements');
    }

    #[Route(
        '/supplements/{id}/delete',
        name: 'app_supplement_delete',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function delete(
        int $id,
        Request $request,
        SupplementRepository $repo,
        EntityManagerInterface $em,
        TranslatorInterface $translator,
    ): Response {
        $supplement = $this->ownedSupplement($id, $repo);

        if ($this->isCsrfTokenValid('supplement-delete-' . $id, (string) $request->request->get('_token'))) {
            $file = $this->supplementsDir . '/' . basename((string) $supplement->getImagePath());
            if ($supplement->getImagePath() && is_file($file)) {
                unlink($file);
            }
            $em->remove($supplement);
            $em->flush();
            $this->addFlash('success', $translator->trans('supplement.removed'));
        }

        return $this->redirectToRoute('app_supplements');
    }

    private function ownedSupplement(int $id, SupplementRepository $repo): Supplement
    {
        $supplement = $repo->find($id);
        if ($supplement === null || $supplement->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        return $supplement;
    }

    /**
     * The posted servings-per-day, clamped to a finite non-negative number. A
     * crafted "INF" survives max(0.0, …) and would reach the adequacy math and
     * the DOUBLE column — same guard as the recipe import's servings.
     */
    private function servingsPerDay(Request $request): float
    {
        $servings = (float) $request->request->get('servings_per_day', 1);

        return is_finite($servings) ? max(0.0, $servings) : 1.0;
    }
}
