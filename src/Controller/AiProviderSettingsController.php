<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\AiClient;
use App\Ai\AiFeature;
use App\Ai\UserAiProvider;
use App\Ai\VisionBenchmark;
use App\Entity\User;
use App\Entity\UserAiSettings;
use App\Repository\UserAiSettingsRepository;
use App\Security\UserSecretBox;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Settings ▸ AI provider: a user's own provider, API key and models (ADR 0003 BYOK).
 * Saving here sends every AI call made for this user to their provider with their key
 * ({@see \App\Ai\ResolvingAiClient}); "Use NouriLoop's AI" removes it.
 *
 * The key is write-only: it is encrypted on save, shown afterwards only as its last four
 * characters, and never logged. A blank key field on save keeps the stored one, unless
 * the provider changes (a key belongs to one provider).
 */
final class AiProviderSettingsController extends AbstractController
{
    private const KEY_MAX_LENGTH = 500;
    private const MODEL_MAX_LENGTH = 150;
    private const NOURILOOP = 'nouriloop';

    public function __construct(
        private readonly UserAiSettingsRepository $repository,
        private readonly UserSecretBox $secrets,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/settings/ai', name: 'app_settings_ai', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $user = $this->user();
        $settings = $this->repository->findForUser($user);
        $errors = [];
        $posted = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('settings-ai-save', (string) $request->request->get('_token'))) {
                $this->addFlash('error', $this->translator->trans('common.session_expired'));

                return $this->redirectToRoute('app_settings_ai');
            }
            $posted = [
                'provider' => trim((string) $request->request->get('provider')),
                'base_url' => trim((string) $request->request->get('base_url')),
                'text_model' => trim((string) $request->request->get('text_model')),
                'vision_model' => trim((string) $request->request->get('vision_model')),
            ];
            $key = trim((string) $request->request->get('api_key'));

            if ($posted['provider'] === self::NOURILOOP) {
                $this->remove($settings);

                return $this->redirectToRoute('app_settings_ai');
            }

            $errors = $this->validate($posted, $key, $settings);
            if ($errors === []) {
                $provider = UserAiProvider::from($posted['provider']);
                $photoModelBefore = $settings === null ? null : self::photoModelIdentity($settings);
                if ($settings === null) {
                    $settings = new UserAiSettings($user, $provider);
                    $this->em->persist($settings);
                }
                $settings->setProvider($provider)
                    ->setBaseUrl($provider === UserAiProvider::Custom ? rtrim($posted['base_url'], '/') : null)
                    ->setTextModel($posted['text_model'] !== '' ? $posted['text_model'] : null)
                    ->setVisionModel($posted['vision_model'] !== '' ? $posted['vision_model'] : null);
                if ($key !== '') {
                    $settings->setEncryptedKey($this->secrets->encrypt($key), mb_substr($key, -4));
                }
                // A new photo model (or the same name on another service) hasn't been
                // checked: clear old results and have the page start the benchmark
                // (John, Plan Q10). Saving a key alone never triggers a run.
                $startBenchmark = $photoModelBefore !== self::photoModelIdentity($settings);
                if ($startBenchmark) {
                    $settings->resetBenchmark();
                }
                $this->em->flush();
                $this->addFlash('success', $this->translator->trans('settings.ai.saved'));
                if ($startBenchmark) {
                    $this->addFlash('ai_benchmark_start', '1');
                }

                return $this->redirectToRoute('app_settings_ai');
            }
        }

        return $this->render('settings/ai.html.twig', [
            'settings' => $settings,
            'providers' => UserAiProvider::cases(),
            'posted' => $posted,
            'errors' => $errors,
            'available' => $this->secrets->isConfigured(),
            // Whether a blank key field would keep the stored key: only while the form
            // still names the service that key was saved for.
            'benchmarkCases' => VisionBenchmark::CASES,
            'benchmarkUnits' => VisionBenchmark::UNITS,
            'benchmarkPassed' => $settings?->benchmarkPassed(VisionBenchmark::caseCount()),
            'keyOnFile' => $settings !== null && ($posted === [] || self::sameService(
                $settings,
                UserAiProvider::tryFrom($posted['provider']),
                $posted['base_url'],
            )),
            'defaults' => array_combine(
                array_map(static fn(UserAiProvider $p): string => $p->value, UserAiProvider::cases()),
                array_map(static fn(UserAiProvider $p): ?string => $p->defaultModel(), UserAiProvider::cases()),
            ),
        ], new Response(status: $errors === [] ? 200 : 422));
    }

    #[Route('/settings/ai/test', name: 'app_settings_ai_test', methods: ['POST'])]
    public function test(
        Request $request,
        AiClient $ai,
        LoggerInterface $logger,
        #[Target('ai_connection_test')] RateLimiterFactoryInterface $limiter,
    ): Response {
        $user = $this->user();
        if (!$this->isCsrfTokenValid('settings-ai-test', (string) $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.session_expired'));

            return $this->redirectToRoute('app_settings_ai');
        }
        if ($this->repository->findForUser($user) === null) {
            return $this->redirectToRoute('app_settings_ai');
        }
        if (!$limiter->create('ai-test-user-' . $user->getId())->consume(1)->isAccepted()) {
            $this->addFlash('error', $this->translator->trans('settings.ai.test_rate_limited'));

            return $this->redirectToRoute('app_settings_ai');
        }

        try {
            // Through the full AiClient chain, so the test uses exactly what real calls
            // will: the user's provider and key, recorded as a user-key call.
            $result = $ai->complete(
                AiFeature::ConnectionTest,
                $user,
                'You check that an API connection works. Reply with the single word OK.',
                [['role' => 'user', 'content' => 'Test']],
                16,
            );
            $this->addFlash('success', $this->translator->trans('settings.ai.test_ok', ['model' => $result->model]));
        } catch (\Throwable $e) {
            // Never the key: the exception class and status only.
            $logger->warning('AI connection test failed', [
                'user' => $user->getId(),
                'exception_class' => $e::class,
                'status' => $e instanceof ClientExceptionInterface ? $e->getCode() : null,
            ]);
            $this->addFlash('error', $this->translator->trans(match (true) {
                $e instanceof ClientExceptionInterface && \in_array($e->getCode(), [401, 403], true)
                    => 'settings.ai.test_rejected',
                $e instanceof TransportExceptionInterface => 'settings.ai.test_unreachable',
                default => 'settings.ai.test_failed',
            }));
        }

        return $this->redirectToRoute('app_settings_ai');
    }

    /**
     * Runs one benchmark photo against the user's photo model (the page calls this once per
     * case, so no single request waits on five model calls). JSON: the case's result, how
     * many are done, and the verdict once all are in.
     */
    #[Route(
        '/settings/ai/benchmark/{index}',
        name: 'app_settings_ai_benchmark_case',
        requirements: ['index' => '\d+'],
        methods: ['POST'],
    )]
    public function benchmarkCase(
        int $index,
        Request $request,
        VisionBenchmark $benchmark,
        #[Target('ai_benchmark')] RateLimiterFactoryInterface $limiter,
    ): JsonResponse {
        $user = $this->user();
        $settings = $this->repository->findForUser($user);
        // Nothing to check without the user's own key (and nothing changes), so this answers first.
        if ($settings === null || $index >= VisionBenchmark::caseCount()) {
            return new JsonResponse(['error' => 'not found'], 404);
        }
        if (!$this->isCsrfTokenValid('settings-ai-benchmark', (string) $request->request->get('_token'))) {
            return new JsonResponse(['error' => $this->translator->trans('common.session_expired')], 403);
        }
        if (!$limiter->create('ai-benchmark-user-' . $user->getId())->consume(1)->isAccepted()) {
            return new JsonResponse(['error' => $this->translator->trans('settings.ai.benchmark.rate_limited')], 429);
        }

        // Photo 0 starts a run: clear the last one, so a rerun that stops partway can't
        // count old cases toward a verdict (Copilot catch on #244).
        if ($index === 0) {
            $settings->resetBenchmark();
        }
        $result = $benchmark->runCase($user, $index);
        $settings->recordBenchmarkCase($result->toArray());
        $this->em->flush();

        return new JsonResponse([
            'case' => $result->toArray(),
            'done' => \count($settings->getBenchmarkResults()),
            'total' => VisionBenchmark::caseCount(),
            'passed' => $settings->benchmarkPassed(VisionBenchmark::caseCount()),
        ]);
    }

    /** No-JavaScript fallback: runs every case in one request, then back to the page. */
    #[Route('/settings/ai/benchmark', name: 'app_settings_ai_benchmark', methods: ['POST'])]
    public function benchmarkAll(
        Request $request,
        VisionBenchmark $benchmark,
        #[Target('ai_benchmark')] RateLimiterFactoryInterface $limiter,
    ): Response {
        $user = $this->user();
        $settings = $this->repository->findForUser($user);
        if (!$this->isCsrfTokenValid('settings-ai-benchmark', (string) $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.session_expired'));

            return $this->redirectToRoute('app_settings_ai');
        }
        if ($settings === null) {
            return $this->redirectToRoute('app_settings_ai');
        }
        $limit = $limiter->create('ai-benchmark-user-' . $user->getId());
        if (!$limit->consume(VisionBenchmark::caseCount())->isAccepted()) {
            $this->addFlash('error', $this->translator->trans('settings.ai.benchmark.rate_limited'));

            return $this->redirectToRoute('app_settings_ai');
        }

        $settings->resetBenchmark();
        for ($i = 0; $i < VisionBenchmark::caseCount(); $i++) {
            $settings->recordBenchmarkCase($benchmark->runCase($user, $i)->toArray());
        }
        $this->em->flush();

        return $this->redirectToRoute('app_settings_ai');
    }

    /** What the benchmark results depend on: the service and the photo model it serves. */
    private static function photoModelIdentity(UserAiSettings $settings): string
    {
        return implode('|', [
            $settings->getProvider()->value,
            $settings->getBaseUrl(),
            $settings->effectiveVisionModel(),
        ]);
    }

    /**
     * @param array{provider: string, base_url: string, text_model: string, vision_model: string} $posted
     *
     * @return array<string, string> field => translation key
     */
    private function validate(array $posted, string $key, ?UserAiSettings $settings): array
    {
        $errors = [];
        if (!$this->secrets->isConfigured()) {
            return ['provider' => 'settings.ai.unavailable'];
        }
        $provider = UserAiProvider::tryFrom($posted['provider']);
        if ($provider === null) {
            return ['provider' => 'settings.ai.error.provider'];
        }

        // A key belongs to one service: switching provider, or pointing a custom service at
        // a different address, needs a new key, so a saved secret is never sent to a
        // service it wasn't issued for (Copilot catch on #243).
        $keyRequired = $settings === null || !self::sameService($settings, $provider, $posted['base_url']);
        if ($key === '' && $keyRequired) {
            $errors['api_key'] = 'settings.ai.error.key_required';
        } elseif (mb_strlen($key) > self::KEY_MAX_LENGTH || preg_match('/\s/', $key) === 1) {
            $errors['api_key'] = 'settings.ai.error.key_format';
        }

        if ($provider === UserAiProvider::Custom) {
            $urlError = self::baseUrlError($posted['base_url']);
            if ($urlError !== null) {
                $errors['base_url'] = $urlError;
            }
            if ($posted['text_model'] === '') {
                $errors['text_model'] = 'settings.ai.error.model_required';
            }
        }
        foreach (['text_model', 'vision_model'] as $field) {
            if (!isset($errors[$field]) && mb_strlen($posted[$field]) > self::MODEL_MAX_LENGTH) {
                $errors[$field] = 'settings.ai.error.model_format';
            }
        }

        return $errors;
    }

    /**
     * A custom endpoint must be https (the user's key rides in the request) and must not
     * name a private, loopback or link-local address. This is the early, readable check;
     * the real guard is NoPrivateNetworkHttpClient at request time, which also catches a
     * public hostname that resolves to a private address.
     */
    private static function baseUrlError(string $url): ?string
    {
        $parts = parse_url($url);
        if ($url === '' || $parts === false || !isset($parts['scheme'], $parts['host'])) {
            return 'settings.ai.error.url_format';
        }
        if (strtolower($parts['scheme']) !== 'https') {
            return 'settings.ai.error.url_https';
        }
        $host = strtolower(trim($parts['host'], '[]'));
        if (
            $host === 'localhost' || str_ends_with($host, '.localhost')
            || (filter_var($host, \FILTER_VALIDATE_IP) !== false && IpUtils::checkIp($host, IpUtils::PRIVATE_SUBNETS))
        ) {
            return 'settings.ai.error.url_private';
        }

        return null;
    }

    /** Whether the posted provider (and, for a custom service, address) is the one the key was saved for. */
    private static function sameService(UserAiSettings $settings, ?UserAiProvider $provider, string $baseUrl): bool
    {
        if ($provider !== $settings->getProvider()) {
            return false;
        }

        return $provider !== UserAiProvider::Custom
            || strtolower(rtrim($baseUrl, '/')) === strtolower((string) $settings->getBaseUrl());
    }

    private function remove(?UserAiSettings $settings): void
    {
        if ($settings !== null) {
            $this->em->remove($settings);
            $this->em->flush();
        }
        $this->addFlash('success', $this->translator->trans('settings.ai.removed'));
    }

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
