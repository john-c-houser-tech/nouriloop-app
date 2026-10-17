<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\UserAiSettings;
use App\Security\UserSecretBox;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds the {@see AiClient} for one user's own provider and key (Settings ▸ AI
 * provider). Model slots: the text model serves every text feature, light ones
 * included; the vision model serves the photo features; a blank slot is "Let
 * NouriLoop choose" ({@see UserAiProvider::defaultModel()}), and a blank vision model
 * means the text model.
 *
 * ⚠️ A custom endpoint is a URL the user typed, so its requests go through
 * {@see NoPrivateNetworkHttpClient}: it resolves the host itself, refuses private,
 * loopback, link-local and reserved addresses, and pins the request to the address it
 * checked, so a user can't point our server at its own network (SSRF), DNS tricks
 * included. Anthropic and OpenRouter are fixed public URLs and don't need it.
 */
final class UserAiClientBuilder
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly UserSecretBox $secrets,
    ) {
    }

    public function build(UserAiSettings $settings): AiClient
    {
        $key = $this->secrets->decrypt($settings->getEncryptedKey());
        $text = $settings->getTextModel() ?? '';
        $vision = $settings->getVisionModel() ?? '';

        return match ($settings->getProvider()) {
            // The text slot serves light features too, so a blank one means the provider
            // default (Sonnet) for help answers as well, not the instance's Haiku: the page
            // promises "NouriLoop chooses" means one model (Copilot catch on #243).
            UserAiProvider::Anthropic => new AnthropicClient(
                $this->httpClient,
                $key,
                $text,
                $text !== '' ? $text : (string) UserAiProvider::Anthropic->defaultModel(),
                $vision,
            ),
            UserAiProvider::OpenRouter => new OpenAiCompatibleClient(
                $this->httpClient,
                UserAiProvider::OPENROUTER_BASE_URL,
                $key,
                $text !== '' ? $text : (string) UserAiProvider::OpenRouter->defaultModel(),
                $text,
                $vision,
            ),
            UserAiProvider::Custom => new OpenAiCompatibleClient(
                new NoPrivateNetworkHttpClient($this->httpClient),
                (string) $settings->getBaseUrl(),
                $key,
                $text,
                $text,
                $vision,
            ),
        };
    }
}
