<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\TurnstileValidator;
use App\SiteContact\FormToken;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;

/**
 * The contact form on the LLC business site (nouriloop.com, the static `nouriloop-site`
 * repo, Transformation Plan §3A item 4). That site has no server, so its plain HTML form
 * posts here and the app emails the message to SITE_CONTACT_TO through its own mailer,
 * without depending on IONOS. Hosted only: a self-hosted copy answers 404.
 *
 * The visitor is on another origin, so there is no CSRF token and no session. Each
 * outcome redirects back to a static page on SITE_CONTACT_ORIGIN, and the defenses are a
 * honeypot, a signed form token the page fetches with JavaScript (FormToken), a per-IP cap,
 * a Cloudflare Turnstile check (TurnstileValidator, once its keys are set) and a check
 * that any Origin sent is the business site's.
 * The recipient is fixed, so the worst abuse is spam to our own inbox.
 */
class SiteContactController extends AbstractController
{
    public const MAX_NAME = 200;
    public const MAX_MESSAGE = 5000;

    public function __construct(
        #[Autowire('%env(SITE_CONTACT_ORIGIN)%')] private readonly string $siteOrigin,
        #[Autowire('%env(SITE_CONTACT_TO)%')] private readonly string $recipient,
    ) {
    }

    /**
     * The page asks for a token when it loads, and for the Turnstile site key (empty while
     * Turnstile is off, so the page then shows no widget). Readable only from the business
     * site's origin (CORS), never cached, and worthless on its own: the token just says when
     * the form was opened, and the site key is public by design.
     */
    #[Route(
        '/site-contact/token',
        name: 'app_site_contact_token',
        methods: ['GET'],
        condition: "service('hosting').isHosted()",
    )]
    public function token(FormToken $formToken, TurnstileValidator $turnstile): Response
    {
        $response = new JsonResponse([
            'token' => $formToken->issue(),
            'turnstileSiteKey' => $turnstile->isEnabled() ? $turnstile->siteKey() : '',
        ]);
        $response->headers->set('Access-Control-Allow-Origin', rtrim($this->siteOrigin, '/'));
        $response->headers->set('Vary', 'Origin');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route(
        '/site-contact',
        name: 'app_site_contact',
        methods: ['POST'],
        condition: "service('hosting').isHosted()",
    )]
    public function submit(
        Request $request,
        MailerInterface $mailer,
        LoggerInterface $logger,
        FormToken $formToken,
        TurnstileValidator $turnstile,
        #[Target('site_contact')] RateLimiterFactoryInterface $siteContactLimiter,
    ): Response {
        $origin = rtrim($this->siteOrigin, '/');

        // Browsers send Origin on a cross-site POST; one naming another site means someone
        // else's page is posting to us. An opaque "null" (a sandboxed iframe, for one) is
        // refused too; only an absent header (a non-browser client) is let through.
        $sentOrigin = $request->headers->get('Origin');
        if ($sentOrigin !== null && rtrim($sentOrigin, '/') !== $origin) {
            return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        }

        // Honeypot tripped → look successful but send nothing.
        if (trim((string) $request->request->get('website', '')) !== '') {
            return $this->back($origin, 'thanks');
        }

        // No token, a forged or stale one, or one used within seconds of the page opening:
        // a script posting the HTML form, or a visitor with JavaScript off. Say so on the
        // problem page, which also gives the address and phone number.
        if (!$formToken->isValid((string) $request->request->get('ft', ''))) {
            return $this->back($origin, 'problem', 'check');
        }

        if (!$siteContactLimiter->create('site-contact-ip-' . $request->getClientIp())->consume()->isAccepted()) {
            return $this->back($origin, 'problem', 'limited');
        }

        $name = trim((string) $request->request->get('name', ''));
        $email = trim((string) $request->request->get('email', ''));
        $message = trim((string) $request->request->get('message', ''));

        $violations = Validation::createValidator()->validate(
            ['name' => $name, 'email' => $email, 'message' => $message],
            new Assert\Collection([
                'name' => new Assert\Length(max: self::MAX_NAME),
                'email' => [new Assert\NotBlank(), new Assert\Email(), new Assert\Length(max: 180)],
                'message' => [new Assert\NotBlank(), new Assert\Length(max: self::MAX_MESSAGE)],
            ]),
        );
        if (\count($violations) > 0) {
            return $this->back($origin, 'problem', 'invalid');
        }

        $turnstileResponse = (string) $request->request->get('cf-turnstile-response', '');
        if (!$turnstile->verify($turnstileResponse, $request->getClientIp())) {
            return $this->back($origin, 'problem', 'check');
        }

        try {
            $mailer->send((new TemplatedEmail())
                ->to($this->recipient)
                ->replyTo(new Address($email, $name))
                ->subject($name !== '' ? sprintf('Business site: %s', $name) : 'Business site message')
                ->htmlTemplate('email/site_contact.html.twig')
                ->context(['name' => $name, 'fromEmail' => $email, 'message' => $message]));
        } catch (\Throwable $e) {
            $logger->warning('Failed to send business-site contact email: {error}', ['error' => $e->getMessage()]);

            return $this->back($origin, 'problem', 'error');
        }

        return $this->back($origin, 'thanks');
    }

    /** Sends the visitor back to the static `contact/thanks/` or `contact/problem/` page. */
    private function back(string $origin, string $page, ?string $reason = null): RedirectResponse
    {
        $url = sprintf('%s/contact/%s/', $origin, $page);
        if ($reason !== null) {
            $url .= '?reason=' . $reason;
        }

        return new RedirectResponse($url, Response::HTTP_SEE_OTHER);
    }
}
