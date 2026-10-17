<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Ai\UserAiProvider;
use App\Entity\User;
use App\Entity\UserAiSettings;
use App\Security\UserSecretBox;
use App\Service\AccountDeletionService;
use App\Tests\Http\FakeAnthropicResponder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Settings ▸ AI provider (ADR 0003 BYOK): the key is stored encrypted and shown only as
 * a hint, calls for the user go to their provider with their key and are recorded as
 * user-key calls, a rejected key never falls back to NouriLoop's, and a custom
 * endpoint must be https and public (checked on save and again at request time).
 */
final class AiProviderSettingsTest extends WebTestCase
{
    use DbResetTrait;

    private const KEY = 'sk-ant-test-1234567890-wxyz';

    public function testAnAnthropicKeyIsStoredEncryptedAndShownOnlyAsItsLastFour(): void
    {
        [$client, $user] = $this->signedIn();

        $this->save($client, ['provider' => 'anthropic', 'api_key' => self::KEY]);
        self::assertResponseRedirects('/settings/ai');

        $row = $this->conn()->fetchAssociative('SELECT * FROM user_ai_settings WHERE user_id = ?', [$user->getId()]);
        self::assertIsArray($row);
        self::assertStringStartsWith('v1:', $row['encrypted_key']);
        self::assertStringNotContainsString(self::KEY, $row['encrypted_key']);
        self::assertSame('wxyz', $row['key_hint']);
        self::assertSame(self::KEY, static::getContainer()->get(UserSecretBox::class)->decrypt($row['encrypted_key']));

        $html = (string) $client->request('GET', '/settings/ai')->html();
        self::assertStringContainsString('Using your own key for Anthropic (•••• wxyz).', $html);
        self::assertStringNotContainsString(self::KEY, $html);
    }

    public function testAUsersCallsGoThroughTheirKeyAndAreRecordedAsTheirs(): void
    {
        [$client, $user] = $this->signedIn();
        $this->save($client, ['provider' => 'anthropic', 'api_key' => self::KEY]);

        $this->testConnection($client);
        self::assertSelectorTextContains('.alert-success', 'It works: claude-sonnet-4-6 answered.');

        $call = $this->conn()->fetchAssociative('SELECT * FROM ai_call');
        self::assertSame('connection_test', $call['feature']);
        self::assertSame($user->getId(), (int) $call['user_id']);
        self::assertSame('anthropic', $call['provider']);
        self::assertSame(1, (int) $call['user_key']);
    }

    public function testARejectedKeyFailsAndNeverFallsBackToNouriLoops(): void
    {
        [$client] = $this->signedIn();
        $this->save($client, ['provider' => 'anthropic', 'api_key' => FakeAnthropicResponder::REJECTED_KEY]);

        $this->testConnection($client);
        self::assertSelectorTextContains('.alert-danger', 'The service rejected your key.');
        self::assertSame(0, (int) $this->conn()->fetchOne('SELECT COUNT(*) FROM ai_call'));
    }

    public function testOpenRouterUsesChatCompletionsWithNouriLoopsDefaultModel(): void
    {
        [$client] = $this->signedIn();
        $this->save($client, ['provider' => 'openrouter', 'api_key' => 'sk-or-test-abcd']);

        $this->testConnection($client);
        $default = (string) UserAiProvider::OpenRouter->defaultModel();
        self::assertSelectorTextContains('.alert-success', 'It works: ' . $default . ' answered.');
        self::assertSame('openrouter.ai', $this->conn()->fetchOne('SELECT provider FROM ai_call'));
    }

    public function testACustomEndpointMustBeHttpsPublicAndNameItsModel(): void
    {
        [$client, $user] = $this->signedIn();
        $cases = [
            ['http://203.0.113.10/v1', 'm', 'must start with https://'],
            ['https://127.0.0.1/v1', 'm', 'private network or this computer'],
            ['https://localhost/v1', 'm', 'private network or this computer'],
            ['https://10.1.2.3/v1', 'm', 'private network or this computer'],
            ['https://[::1]/v1', 'm', 'private network or this computer'],
            ['https://203.0.113.10/v1', '', 'Enter the model name'],
        ];
        foreach ($cases as [$url, $model, $message]) {
            $this->save($client, [
                'provider' => 'custom', 'api_key' => 'k-1234', 'base_url' => $url, 'text_model' => $model,
            ]);
            self::assertResponseStatusCodeSame(422, $url);
            self::assertSelectorTextContains('#ai-form', $message);
        }
        self::assertSame(0, (int) $this->conn()->fetchOne('SELECT COUNT(*) FROM user_ai_settings'));

        // A public address over https with a model saves, and the connection test reaches it.
        $this->save($client, [
            'provider' => 'custom', 'api_key' => 'k-1234',
            'base_url' => 'https://203.0.113.10/v1/', 'text_model' => 'llama-x',
        ]);
        self::assertResponseRedirects('/settings/ai');
        self::assertSame('https://203.0.113.10/v1', $this->conn()->fetchOne('SELECT base_url FROM user_ai_settings'));
        $client->followRedirect();
        self::assertSelectorTextContains('#ai-status', 'Using your own key for a custom service (•••• 1234).');
        $this->testConnection($client);
        self::assertSelectorTextContains('.alert-success', 'It works: llama-x answered.');
    }

    public function testThePrivateNetworkGuardHoldsEvenWhenTheFormIsBypassed(): void
    {
        [$client, $user] = $this->signedIn();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $settings = (new UserAiSettings($em->getReference(User::class, $user->getId()), UserAiProvider::Custom))
            ->setBaseUrl('https://10.0.0.5/v1')
            ->setTextModel('m')
            ->setEncryptedKey(static::getContainer()->get(UserSecretBox::class)->encrypt('k-1234'), '1234');
        $em->persist($settings);
        $em->flush();

        $this->testConnection($client);
        self::assertSelectorTextContains('.alert-danger', "NouriLoop couldn't reach that service.");
        self::assertSame(0, (int) $this->conn()->fetchOne('SELECT COUNT(*) FROM ai_call'));
    }

    public function testABlankKeyKeepsTheStoredOneButASwitchOfProviderNeedsANewKey(): void
    {
        [$client] = $this->signedIn();
        $this->save($client, ['provider' => 'anthropic', 'api_key' => self::KEY]);
        $stored = $this->conn()->fetchOne('SELECT encrypted_key FROM user_ai_settings');

        $this->save($client, ['provider' => 'anthropic', 'api_key' => '', 'text_model' => 'claude-sonnet-5-5']);
        self::assertResponseRedirects('/settings/ai');
        self::assertSame($stored, $this->conn()->fetchOne('SELECT encrypted_key FROM user_ai_settings'));
        self::assertSame('claude-sonnet-5-5', $this->conn()->fetchOne('SELECT text_model FROM user_ai_settings'));

        $this->save($client, ['provider' => 'openrouter', 'api_key' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#ai-form', 'Enter the API key for this service.');
    }

    public function testPointingACustomServiceAtANewAddressNeedsANewKey(): void
    {
        // Copilot catch on #243: same provider (custom), different address. A blank key
        // must not carry the saved secret to a service it wasn't issued for.
        [$client] = $this->signedIn();
        $this->save($client, [
            'provider' => 'custom', 'api_key' => 'k-first-service',
            'base_url' => 'https://203.0.113.10/v1', 'text_model' => 'm',
        ]);
        self::assertResponseRedirects('/settings/ai');
        $stored = $this->conn()->fetchOne('SELECT encrypted_key FROM user_ai_settings');

        $this->save($client, [
            'provider' => 'custom', 'api_key' => '', 'base_url' => 'https://198.51.100.7/v1', 'text_model' => 'm',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#ai-form', 'Enter the API key for this service.');
        self::assertSelectorTextNotContains('#ai-form', 'Leave blank to keep the key you saved.');
        self::assertSame('https://203.0.113.10/v1', $this->conn()->fetchOne('SELECT base_url FROM user_ai_settings'));
        self::assertSame($stored, $this->conn()->fetchOne('SELECT encrypted_key FROM user_ai_settings'));

        // The same address (trailing slash and case aside) keeps the saved key.
        $this->save($client, [
            'provider' => 'custom', 'api_key' => '', 'base_url' => 'https://203.0.113.10/V1/', 'text_model' => 'm2',
        ]);
        self::assertResponseRedirects('/settings/ai');
    }

    public function testAFailedProviderSwitchDoesNotPromiseToKeepTheSavedKey(): void
    {
        [$client] = $this->signedIn();
        $this->save($client, ['provider' => 'anthropic', 'api_key' => self::KEY]);

        $this->save($client, ['provider' => 'openrouter', 'api_key' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextNotContains('#ai-form', 'Leave blank to keep the key you saved.');
        self::assertSame('', $client->getCrawler()->filter('#api_key')->attr('placeholder'));
    }

    public function testWhenTheServerCannotReadKeysASavedUserCanStillSwitchBack(): void
    {
        [$client, $user] = $this->signedIn();
        $this->save($client, ['provider' => 'anthropic', 'api_key' => self::KEY]);

        // Simulate USER_SECRETS_KEY going missing: a fresh kernel whose box reports
        // unconfigured, kept for the next requests.
        $client->getKernel()->shutdown();
        $client->getKernel()->boot();
        static::getContainer()->set(UserSecretBox::class, new UserSecretBox(''));
        $client->disableReboot();
        $crawler = $client->request('GET', '/settings/ai');
        self::assertSelectorNotExists('#ai-form');
        $client->submit($crawler->filter('#ai-reset')->form());

        self::assertResponseRedirects('/settings/ai');
        self::assertSame(0, (int) $this->conn()->fetchOne('SELECT COUNT(*) FROM user_ai_settings'));
    }

    public function testChoosingNouriLoopsAiRemovesTheKeyAndPurgingTheAccountDeletesIt(): void
    {
        [$client, $user] = $this->signedIn();
        $this->save($client, ['provider' => 'anthropic', 'api_key' => self::KEY]);

        $this->save($client, ['provider' => 'nouriloop']);
        self::assertResponseRedirects('/settings/ai');
        self::assertSame(0, (int) $this->conn()->fetchOne('SELECT COUNT(*) FROM user_ai_settings'));

        $this->save($client, ['provider' => 'anthropic', 'api_key' => self::KEY]);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        static::getContainer()->get(AccountDeletionService::class)->purgeUser($em->find(User::class, $user->getId()));
        self::assertSame(0, (int) $this->conn()->fetchOne('SELECT COUNT(*) FROM user_ai_settings'));
    }

    public function testTheSettingsHubLinksTheAiProviderPage(): void
    {
        [$client] = $this->signedIn();
        $client->request('GET', '/settings');
        self::assertSelectorExists('a[href="/settings/ai"]');
    }

    /** @return array{0: KernelBrowser, 1: User} */
    private function signedIn(): array
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $user = new User();
        $user->setEmail('byok@test.local');
        $user->setDisplayName('Key Holder');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable('-1 day'));
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();

        static::getContainer()->get('limiter.ai_connection_test')->create('ai-test-user-' . $user->getId())->reset();
        $client->loginUser($user);

        return [$client, $user];
    }

    /** @param array<string, string> $values */
    private function save(KernelBrowser $client, array $values): void
    {
        $crawler = $client->request('GET', '/settings/ai');
        $form = $crawler->filter('#ai-form')->form();
        $client->submit($form, $values + ['api_key' => '', 'base_url' => '', 'text_model' => '', 'vision_model' => '']);
    }

    private function testConnection(KernelBrowser $client): void
    {
        $crawler = $client->request('GET', '/settings/ai');
        $client->submit($crawler->filter('#ai-test')->form());
        self::assertResponseRedirects('/settings/ai');
        $client->followRedirect();
    }

    private function conn(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
