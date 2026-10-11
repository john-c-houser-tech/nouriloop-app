<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\HealthJsonImportResolver;
use App\Service\HealthMetricApiParser;
use App\Service\HealthMetricIngestion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class HealthMetricApiController extends AbstractController
{
    /** Cap per request so a sync stays small and payloads stay bounded. */
    private const MAX_RECORDS = 1000;

    /** Hard ceiling on the raw Health Export body (its daily aggregates are tiny). */
    private const MAX_HEALTH_EXPORT_BYTES = 5 * 1024 * 1024;

    /**
     * Bearer-authenticated ingestion endpoint for mobile apps / sync scripts.
     * Body: { "metrics": [ {type, value, unit?, recordedAt, source?}, ... ] }.
     * Dedup is handled downstream (INSERT IGNORE on uq_health_metric), so
     * re-sending overlapping ranges is safe.
     */
    #[Route('/health-metrics', name: 'api_health_metrics', methods: ['POST'])]
    public function ingest(
        Request $request,
        HealthMetricApiParser $parser,
        HealthMetricIngestion $ingestion,
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload) || !isset($payload['metrics']) || !is_array($payload['metrics'])) {
            return $this->json(
                ['error' => 'Body must be JSON of the form { "metrics": [ … ] }.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if (count($payload['metrics']) > self::MAX_RECORDS) {
            return $this->json(
                ['error' => sprintf('Too many metrics in one request (max %d).', self::MAX_RECORDS)],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        /** @var User $user */
        $user = $this->getUser();

        $parsed  = $parser->parse($payload['metrics']);
        $written = $ingestion->ingest($user, $parsed['readings']);

        $errors = array_merge($parsed['errors'], $written['errors']);

        return $this->json([
            'imported' => $written['imported'],
            'skipped'  => $parsed['skipped'] + $written['skipped'],
            'errors'   => array_slice($errors, 0, 50),
        ]);
    }

    /**
     * Ingests a health-export app's JSON verbatim. The right importer is chosen
     * by auto-detection, or forced with `?app=<key>` (the CLI's `--app=`). The
     * CLI posts the exported file unchanged; the mapping lives server-side in the
     * importers (single source of truth). Dedup is handled downstream, so
     * re-posting an overlapping export is safe.
     */
    #[Route('/health-metrics/health-export', name: 'api_health_metrics_health_export', methods: ['POST'])]
    public function healthExport(
        Request $request,
        HealthJsonImportResolver $resolver,
    ): JsonResponse {
        $content = $request->getContent();
        if (strlen($content) > self::MAX_HEALTH_EXPORT_BYTES) {
            return $this->json(
                ['error' => sprintf('Payload too large (max %d bytes).', self::MAX_HEALTH_EXPORT_BYTES)],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $payload = json_decode($content, true);
        if (!is_array($payload)) {
            return $this->json(
                ['error' => 'Body must be a health-export JSON object.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        /** @var User $user */
        $user   = $this->getUser();
        $appKey = $request->query->get('app') ?: null;

        try {
            $result = $resolver->import($payload, $user, $appKey);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'imported' => $result['imported'],
            'skipped'  => $result['skipped'],
            'errors'   => array_slice($result['errors'], 0, 50),
        ]);
    }
}
