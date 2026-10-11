<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\HealthMetricType;
use App\Repository\HealthMetricRepository;
use App\Service\AppleHealthXmlImporter;
use App\Service\HealthJsonImportResolver;
use App\Service\HealthMetricCsvImporter;
use App\Service\OnboardingService;
use App\Service\UnitConverter;
use App\Service\UserTime;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Logging\Middleware;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/health-metrics')]
class HealthMetricsController extends AbstractController
{
    #[Route('', name: 'app_health_metrics')]
    public function index(
        Request $request,
        HealthMetricRepository $repo,
        UserTime $userTime,
        UnitConverter $units,
        TranslatorInterface $translator,
    ): Response {
        $days = (int) $request->query->get('days', 30);
        $days = in_array($days, [7, 14, 30, 90], true) ? $days : 30;
        $user = $this->getUser();
        \assert($user instanceof User);
        $unitSystem = $user->getUnitSystem();

        $from = $userTime->startOfDaysAgo($user, $days);
        $to   = $userTime->startOfTomorrow($user);

        // Use hourly buckets for short windows; daily for longer ones.
        $granularity = $days <= 14 ? 'hour' : 'day';

        $metricsByType = [];
        foreach (HealthMetricType::cases() as $type) {
            if ($type->isSleepType()) {
                continue; // sleep requires SUM aggregation; shown on Trends page instead
            }
            $buckets = $repo->getAggregates($user, $type, $from, $to, $granularity);
            if (!empty($buckets)) {
                $totalCount = array_sum(array_column($buckets, 'count'));
                $metricsByType[$type->value] = [
                    'label'       => $translator->trans($type->transKey()),
                    'unit'        => $units->displayUnit($type, $unitSystem),
                    'count'       => $totalCount,
                    'buckets'     => $units->convertAggregateRows($type, $buckets, $unitSystem),
                    'granularity' => $granularity,
                    // Describes what the headline number is (daily totals / averages /
                    // readings), matching how getAggregates summarized it.
                    'bucketLabel' => $translator->trans($type->summary()->bucketTransKey($granularity)),
                ];
            }
        }

        return $this->render('health_metrics/index.html.twig', [
            'metricsByType' => $metricsByType,
            'days'          => $days,
            'hasGlucose'    => $repo->userHasGlucose($user),
        ]);
    }

    #[Route('/import', name: 'app_health_metrics_import', methods: ['GET', 'POST'])]
    public function import(
        Request $request,
        HealthMetricCsvImporter $csvImporter,
        AppleHealthXmlImporter $xmlImporter,
        HealthJsonImportResolver $jsonResolver,
        OnboardingService $onboarding,
        TranslatorInterface $translator,
    ): Response {
        if ($request->isMethod('POST')) {
            // A hand-rolled form, not the Form component, so nothing checks CSRF
            // for us — and the stateless same-origin scheme only covers the Form
            // component's ids. Checked before any of the (expensive) import work.
            if (!$this->isCsrfTokenValid('health-import', (string) $request->request->get('_token'))) {
                $this->addFlash('error', $translator->trans('common.session_expired'));

                return $this->redirectToRoute('app_health_metrics_import');
            }

            // Large imports are memory-hungry (the dev-mode profiler buffers every
            // query log entry); raise the cap for this request only — but keep it
            // finite so a crafted upload can't OOM the box — and silence DBAL logging.
            ini_set('memory_limit', '512M');
            $this->silenceDbalLogging();

            /** @var UploadedFile|null $file */
            $file = $request->files->get('health_file');

            // Ingestion is upload-only. (The former "local file path" option read a
            // file off the *server* filesystem and is gone — it was a single-user
            // local-Mac convenience that, multi-user, let any account read arbitrary
            // server files. Large histories go through the sync CLI / API instead.)
            if ($file === null) {
                $this->addFlash('error', $translator->trans('health.import.choose_file'));

                return $this->redirectToRoute('app_health_metrics_import');
            }

            // A file can be present but failed at the PHP layer (over
            // upload_max_filesize/post_max_size, partial upload, …). Catch it
            // here with an actionable message instead of a confusing parse error
            // downstream — this endpoint bypasses the Form's File constraints.
            if (!$file->isValid()) {
                $this->addFlash('error', $translator->trans(
                    'health.import.upload_failed',
                    ['error' => $file->getErrorMessage()],
                ));

                return $this->redirectToRoute('app_health_metrics_import');
            }

            $filePath = $file->getPathname();
            $format   = $this->detectFormat($file);

            try {
                if ($format === 'xml') {
                    $result      = $xmlImporter->import($filePath, $this->getUser());
                    $formatLabel = 'Apple Health XML';
                } elseif ($format === 'json') {
                    $appKey      = $request->request->get('json_app') ?: null;
                    $result      = $jsonResolver->importFile($filePath, $this->getUser(), $appKey);
                    $formatLabel = 'health-export JSON';
                } else {
                    $source      = $request->request->get('source', 'CSV Import') ?: 'CSV Import';
                    $result      = $csvImporter->import($filePath, $this->getUser(), $source);
                    $formatLabel = 'CSV';
                }
            } catch (\Throwable $e) {
                $this->addFlash('error', $translator->trans('health.import.failed', ['error' => $e->getMessage()]));

                return $this->redirectToRoute('app_health_metrics_import');
            }

            $this->addFlash('success', $translator->trans('health.import.done', [
                'imported' => $result['imported'],
                'format'   => $formatLabel,
                'skipped'  => $result['skipped'],
            ]));

            foreach (array_slice($result['errors'], 0, 5) as $error) {
                $this->addFlash('warning', $error);
            }

            if (count($result['errors']) > 5) {
                $this->addFlash('warning', $translator->trans(
                    'health.import.more_errors',
                    ['count' => count($result['errors']) - 5],
                ));
            }

            // A successful import satisfies the optional onboarding "connect health data" step.
            $user = $this->getUser();
            \assert($user instanceof User);
            $onboarding->markStep($user, 'health');

            return $this->redirectToRoute('app_health_metrics');
        }

        return $this->render('health_metrics/import.html.twig', [
            'json_apps' => $jsonResolver->choices(),
        ]);
    }

    /**
     * Replaces the DBAL connection's logger with a no-op so that the Symfony
     * dev-mode profiler does not buffer thousands of query log entries in memory.
     */
    private function silenceDbalLogging(): void
    {
        try {
            /** @var Connection $conn */
            $conn = $this->container->get('doctrine')->getConnection();
            $conn->getConfiguration()->setMiddlewares(
                array_filter(
                    $conn->getConfiguration()->getMiddlewares(),
                    fn($m) => !$m instanceof Middleware,
                )
            );
        } catch (\Throwable) {
            // Non-fatal — logging stays on but import still runs.
        }
    }

    private function detectFormat(UploadedFile $file): string
    {
        $ext      = strtolower($file->getClientOriginalExtension());
        $mimeType = $file->getMimeType() ?? '';

        if ($ext === 'xml' || str_contains($mimeType, 'xml')) {
            return 'xml';
        }

        if ($ext === 'json' || str_contains($mimeType, 'json')) {
            return 'json';
        }

        // Peek at the first bytes to detect format regardless of extension
        $handle = fopen($file->getPathname(), 'r');
        if ($handle !== false) {
            $peek = fread($handle, 100);
            fclose($handle);
            if ($peek !== false) {
                if (str_contains($peek, '<?xml')) {
                    return 'xml';
                }
                $trimmed = ltrim($peek);
                if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
                    return 'json';
                }
            }
        }

        return 'csv';
    }
}
