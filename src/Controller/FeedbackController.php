<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\FeedbackFormType;
use App\Hosting\Brand;
use App\Service\TurnstileValidator;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public "Send us feedback" form, linked from the global footer. Name + email are
 * optional, feedback is required. Not persisted — emailed to the team (Reply-To set
 * to the submitter when they leave an email). Spam defenses: a honeypot field +
 * Cloudflare Turnstile.
 */
class FeedbackController extends AbstractController
{
    public function __construct(
        private readonly Brand $brand,
    ) {
    }

    #[Route('/feedback', name: 'app_feedback', methods: ['GET', 'POST'])]
    public function feedback(
        Request $request,
        MailerInterface $mailer,
        LoggerInterface $logger,
        TurnstileValidator $turnstile,
        string $adminEmail,
    ): Response {
        // Pre-fill for signed-in users so they don't retype.
        $user = $this->getUser();
        $form = $this->createForm(FeedbackFormType::class, [
            'name' => $user?->getDisplayName(),
            'email' => $user?->getUserIdentifier(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            // Honeypot tripped → look successful but send nothing.
            if (trim((string) ($data['website'] ?? '')) !== '') {
                return $this->redirectToRoute('app_feedback_sent');
            }

            if (!$turnstile->verify($request->request->get('cf-turnstile-response'), $request->getClientIp())) {
                $this->addFlash('error', 'Could not verify you are human. Please try again.');

                return $this->render('feedback/new.html.twig', [
                    'form' => $form,
                    'turnstileSiteKey' => $turnstile->isEnabled() ? $turnstile->siteKey() : '',
                ]);
            }

            if ($this->sendFeedback($data, $adminEmail, $mailer, $logger)) {
                return $this->redirectToRoute('app_feedback_sent');
            }

            // Not persisted, so a silent failure would lose it — tell the user.
            $this->addFlash('error', "Sorry — we couldn't send that just now. Please try again in a moment.");

            return $this->render('feedback/new.html.twig', [
                'form' => $form,
                'turnstileSiteKey' => $turnstile->isEnabled() ? $turnstile->siteKey() : '',
            ]);
        }

        return $this->render('feedback/new.html.twig', [
            'form' => $form,
            'turnstileSiteKey' => $turnstile->isEnabled() ? $turnstile->siteKey() : '',
        ]);
    }

    #[Route('/feedback/sent', name: 'app_feedback_sent', methods: ['GET'])]
    public function sent(): Response
    {
        return $this->render('feedback/sent.html.twig');
    }

    /**
     * Emails the feedback. Returns false (logged) on a transport failure so the
     * caller can warn the user rather than show a false "sent" page. Uses the global
     * MAILER_FROM; Reply-To is the submitter's address when given, so a reply goes
     * straight back to them.
     *
     * @param array{name?: ?string, email?: ?string, message?: ?string} $data
     */
    private function sendFeedback(
        array $data,
        string $adminEmail,
        MailerInterface $mailer,
        LoggerInterface $logger,
    ): bool {
        $replyTo = trim((string) ($data['email'] ?? ''));

        try {
            $email = (new TemplatedEmail())
                ->to($adminEmail)
                ->subject(sprintf('%s feedback', $this->brand->name()))
                ->htmlTemplate('email/feedback.html.twig')
                ->context([
                    'name' => trim((string) ($data['name'] ?? '')),
                    'fromEmail' => $replyTo,
                    'message' => (string) ($data['message'] ?? ''),
                ]);

            if ($replyTo !== '') {
                $email->replyTo(new Address($replyTo));
            }

            $mailer->send($email);

            return true;
        } catch (\Throwable $e) {
            $logger->warning('Failed to send feedback email: {error}', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
