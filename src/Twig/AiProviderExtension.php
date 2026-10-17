<?php

declare(strict_types=1);

namespace App\Twig;

use App\Ai\VisionBenchmark;
use App\Entity\User;
use App\Repository\UserAiSettingsRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `photo_model_failed_benchmark()`: true when the signed-in user analyses photos with their
 * own model and that model failed the vision benchmark. The meal review shows a short note
 * then; nothing is blocked (John, App Development Plan Q10).
 */
class AiProviderExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly UserAiSettingsRepository $settings,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('photo_model_failed_benchmark', $this->photoModelFailedBenchmark(...))];
    }

    public function photoModelFailedBenchmark(): bool
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return false;
        }
        $settings = $this->settings->findForUser($user);

        return $settings?->benchmarkPassed(VisionBenchmark::caseCount()) === false;
    }
}
