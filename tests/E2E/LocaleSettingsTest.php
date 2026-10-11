<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use App\Enum\SubscriptionTier;
use App\Enum\SupportedLocale;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * i18n Phase 1 infrastructure: the Settings ▸ Language page, the locale
 * subscriber, translator fallback, and registration's Accept-Language
 * detection. In-process WebTestCase (plain form POSTs, no JS), kept under
 * tests/E2E so it runs in the DB-having e2e job.
 */
final class LocaleSettingsTest extends WebTestCase
{
    use DbResetTrait;

    public function testLanguagePageRendersInEnglishWithNativeChoiceLabels(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'locale@test.local');

        $client->loginUser($user);
        $crawler = $client->request('GET', '/settings/language');

        self::assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Choose the language NouriLoop uses with you.', $body);
        // Native names are never translated — every speaker finds their own language.
        self::assertStringContainsString('Español (México)', $body);
        self::assertStringContainsString('Deutsch', $body);
        self::assertStringContainsString('Русский', $body);
        self::assertStringContainsString('हिन्दी', $body);
        self::assertSame('en', $crawler->filter('html')->attr('lang'));
    }

    public function testSwitchingToSpanishTranslatesThePageAndFlash(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'locale@test.local');

        $client->loginUser($user);
        $client->request('GET', '/settings/language');
        $client->submitForm('Save language', [
            'language_form[locale]' => 'es',
        ]);

        self::assertResponseRedirects('/settings/language');
        $crawler = $client->followRedirect();

        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Idioma guardado.', $body, 'flash is in the newly chosen language');
        self::assertStringContainsString('Elige el idioma que NouriLoop usa contigo.', $body);
        // The shared chrome translates too (navbar + notification bell).
        self::assertStringContainsString('Cerrar sesión', $body);
        self::assertStringContainsString('Aún no hay notificaciones.', $body);
        // A fresh user still needs onboarding — the overlay rides along in Spanish.
        self::assertStringContainsString('Registra tu primera comida', $body);
        self::assertSame('es', $crawler->filter('html')->attr('lang'));

        $em->clear();
        $saved = $em->getRepository(User::class)->findOneBy(['email' => 'locale@test.local']);
        self::assertSame(SupportedLocale::Spanish, $saved->getLocale());

        // The settings hub (keyed CATEGORIES labels) translates too.
        $hub = $client->request('GET', '/settings');
        $hubBody = $hub->filter('body')->text();
        self::assertStringContainsString('Preferencias', $hubBody);
        self::assertStringContainsString('Zona horaria', $hubBody);
        self::assertStringContainsString('Eliminar mis datos o mi cuenta', $hubBody);
    }

    public function testEverySettingsSubPageRendersInSpanishWithoutRawKeys(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'locale@test.local');
        $user->setLocale(SupportedLocale::Spanish);
        $em->flush();
        $client->loginUser($user);

        // One representative Spanish string per sub-page: covers form labels
        // (timezone), enum choice labels (units), form help (glp1), the tone
        // dials, choice labels again (notifications), and plain page copy.
        $expectations = [
            '/settings/timezone' => 'Tu zona horaria',
            '/settings/units' => 'Métrico (kg, mmol/L)',
            '/settings/glp1' => 'Activar el modo GLP-1',
            '/settings/tone' => 'Calidez',
            '/settings/notifications' => 'Enviar notificaciones por',
            '/settings/api-tokens' => 'Aún no hay tokens.',
            '/settings/subscription' => 'Plan actual',
            '/settings/account' => 'Empezar de cero',
        ];

        foreach ($expectations as $path => $spanish) {
            $crawler = $client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
            $body = $crawler->filter('body')->text();
            self::assertStringContainsString($spanish, $body, $path);
            // A raw dotted key anywhere means a trans() call missed the catalogs.
            self::assertDoesNotMatchRegularExpression(
                '/\bsettings\.[a-z_]+\.[a-z_]+/',
                $body,
                $path . ' leaked a raw translation key',
            );
        }
    }

    public function testSavingSettingsInSpanishFlashesAndValidatesInSpanish(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'locale@test.local');
        $user->setLocale(SupportedLocale::Spanish);
        // SMS controls only render for sms_digest users (goal tiering) — this test
        // is about the FormError's locale, so make the user entitled via a comp.
        $user->setCompTier(SubscriptionTier::Loop);
        $em->flush();
        $client->loginUser($user);

        // Happy path: saving units flashes in Spanish.
        $client->request('GET', '/settings/units');
        $client->submitForm('Guardar unidades', [
            'units_form[unitSystem]' => 'imperial',
        ]);
        self::assertResponseRedirects('/settings/units');
        $crawler = $client->followRedirect();
        self::assertStringContainsString('Unidades guardadas.', $crawler->filter('body')->text());

        // Validation path: SMS channel without a phone → the controller's
        // FormError renders translated.
        $client->request('GET', '/settings/notifications');
        $crawler = $client->submitForm('Guardar preferencias', [
            'notification_preferences_form[notificationChannel]' => 'sms',
        ]);
        self::assertStringContainsString(
            'Añade un número de celular para recibir mensajes de texto.',
            $crawler->filter('body')->text(),
        );
    }

    public function testEveryFullLanguageRendersItsOwnCatalogWithoutRawKeys(): void
    {
        // Every full-catalog language actually translates the page: the English
        // lead sentence must be REPLACED (not fallen back to) and no raw
        // 'settings.language.…' key may leak. Wording itself isn't pinned —
        // catalogs are curated content; CatalogConsistencyTest guards structure.
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'locale@test.local');
        $client->loginUser($user);

        foreach (
            [
                SupportedLocale::German,
                SupportedLocale::French,
                SupportedLocale::Russian,
                SupportedLocale::Hindi,
            ] as $locale
        ) {
            $lang = $locale->value;
            // Requests in between detach the seeded entity — re-attach by id
            // (same gotcha as the favorites test above).
            $managed = $em->getRepository(User::class)->find($user->getId());
            $managed->setLocale($locale);
            $em->flush();

            $crawler = $client->request('GET', '/settings/language');
            self::assertResponseIsSuccessful();
            $body = $crawler->filter('body')->text();
            self::assertStringNotContainsString(
                'Choose the language NouriLoop uses with you.',
                $body,
                "{$lang}: still showing the English lead — catalog not loaded",
            );
            self::assertStringNotContainsString('settings.language', $body, "{$lang}: raw key leaked");
            self::assertSame(str_replace('_', '-', $lang), $crawler->filter('html')->attr('lang'));
        }
    }

    public function testSparseOverrideLocaleFallsBackToItsBaseForUncoveredKeys(): void
    {
        // en_GB is a sparse spelling override — a key it doesn't carry must fall
        // back to plain en text, never a raw key. (The es_MX → es chain is
        // covered by testRegionalVariantFallsBackToItsBaseCatalog.)
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'locale@test.local');
        $user->setLocale(SupportedLocale::EnglishUk);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/settings/language');

        self::assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Choose the language NouriLoop uses with you.', $body);
        self::assertStringNotContainsString('settings.language', $body);
        self::assertSame('en-GB', $crawler->filter('html')->attr('lang'));
    }

    public function testRegionalVariantFallsBackToItsBaseCatalog(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'locale@test.local');
        // es_MX has no catalog of its own yet → ICU fallback to base es.
        $user->setLocale(SupportedLocale::SpanishMexico);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/settings/language');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'Elige el idioma que NouriLoop usa contigo.',
            $crawler->filter('body')->text(),
        );
        self::assertSame('es-MX', $crawler->filter('html')->attr('lang'));
    }

    public function testDashboardRendersInUserLanguage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'locale@test.local');
        $user->setLocale(SupportedLocale::Spanish);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Aún no has registrado comidas hoy.', $body);
        self::assertStringContainsString('Registrar comida', $body);
        // The ICU select greeting resolved to one of the three periods.
        self::assertMatchesRegularExpression('/¡Buen(os días|as tardes|as noches), Locale Tester!/u', $body);
    }

    public function testBuiltinSymptomLabelsTranslateButCustomOnesDoNot(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'locale@test.local');
        $user->setLocale(SupportedLocale::Spanish);

        $builtin = new \App\Entity\SymptomType();
        $builtin->setUser($user);
        $builtin->setTypeKey('joint_pain');
        $builtin->setLabel('Joint pain');
        $builtin->setBuiltin(true);
        $builtin->setEnabled(true);
        $em->persist($builtin);

        $custom = new \App\Entity\SymptomType();
        $custom->setUser($user);
        $custom->setTypeKey('custom_left_knee');
        $custom->setLabel('Left knee after tennis');
        $custom->setBuiltin(false);
        $custom->setEnabled(true);
        $em->persist($custom);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        // Built-in translates via typeKey; the seeded English label is gone.
        self::assertStringContainsString('Dolor articular', $body);
        self::assertStringNotContainsString('Joint pain', $body);
        // Custom symptom labels are user data — rendered verbatim, never translated.
        self::assertStringContainsString('Left knee after tennis', $body);
    }

    public function testGoalsPagesRenderInUserLanguage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'locale@test.local');
        $user->setLocale(SupportedLocale::Spanish);
        $em->flush();
        $client->loginUser($user);

        // Index (empty state) + the chat setup page incl. the localized greeting.
        $crawler = $client->request('GET', '/goals');
        self::assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Metas de nutrición', $body);
        self::assertStringContainsString('Aún no has definido metas.', $body);

        $crawler = $client->request('GET', '/goals/new');
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Definir una meta', $body);
        self::assertStringContainsString('¡Hola! Puedo ayudarte a definir una meta de nutrición.', $body);

        // Manual form: enum choice labels + the localized reference note.
        $crawler = $client->request('GET', '/goals/new/manual');
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('promedio diario', $body);
        self::assertStringContainsString('como máximo', $body);
        // Note the reference notes sit inside a json_encode'd JS block, so the
        // "/día" slash is escaped — assert a slash-free fragment.
        self::assertStringContainsString('El Valor Diario de sodio es 2300 mg', $body);
        self::assertDoesNotMatchRegularExpression('/\bgoal\.[a-z_]+\.[a-z_]+/', $body);
    }

    public function testTrendsRendersInUserLanguage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'locale@test.local');
        $user->setLocale(SupportedLocale::Spanish);

        // One meal so the page renders the dashboard (not the empty state) and
        // the intake tiles + calories panel have data.
        $meal = new \App\Entity\MealEntry();
        $meal->setUser($user);
        $meal->setMealType(\App\Enum\MealType::Lunch);
        $meal->setLoggedAt(new \DateTimeImmutable('-1 day'));
        $nutrition = new \App\Entity\NutritionData();
        $nutrition->setCalories(650.0);
        $nutrition->setProtein(30.0);
        $meal->setNutritionData($nutrition);
        $em->persist($meal);
        $em->persist($nutrition);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/trends');

        self::assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        // Theme titles from the translator-injected InsightSummaryService.
        self::assertStringContainsString('Lo que comiste', $body);
        // Tile + note strings.
        self::assertStringContainsString('Calorías', $body);
        self::assertStringContainsString('promedio diario', $body);
        // Template prose + the JS L dict.
        self::assertStringContainsString('Patrones de tus últimos', $body);
        // Accented L-dict values are \u-escaped by json_encode — assert an
        // ASCII one to prove the localized JS dict rendered.
        self::assertStringContainsString('"Grasa"', $crawler->html());
        self::assertDoesNotMatchRegularExpression('/\b(trends|insight)\.[a-z_]+\.[a-z_]+/', $body);
    }

    public function testInboxAndFavoritesRenderInUserLanguage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'locale@test.local');
        $user->setLocale(SupportedLocale::Spanish);
        $em->flush();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/notifications');
        self::assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Aún no hay notificaciones.', $body);
        self::assertStringContainsString(
            'Las revisiones de metas y los recordatorios de NouriLoop aparecerán aquí.',
            $body,
        );

        $crawler = $client->request('GET', '/meal/favorites');
        self::assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Comidas favoritas', $body);
        self::assertStringContainsString('Aún no hay favoritos.', $body);
        self::assertStringContainsString('Usados recientemente', $body);

        // Seed a favorite: the populated list exercises the usage count, the
        // meal-type options via transKey, and (via the edit POST) a
        // parameterized controller flash.
        // Requests in between detached the seeded entity — re-attach by id.
        $managedUser = $em->getRepository(User::class)->find($user->getId());
        $favorite = new \App\Entity\Favorite();
        $favorite->setUser($managedUser);
        $favorite->setName('Avena del martes');
        $favorite->setNutrients(['calories' => 320.0, 'protein' => 12.0, 'carbohydrates' => 45.0, 'fat' => 8.0]);
        $favorite->setIngredients([]);
        $favorite->setTokensPerAnalysis(0);
        $favorite->setUseCount(2);
        $em->persist($favorite);
        $em->flush();

        $crawler = $client->request('GET', '/meal/favorites');
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Usado 2×', $body);
        self::assertStringContainsString('Desayuno', $body, 'meal-type options via transKey');
        self::assertStringContainsString('Registrarla', $body);

        $client->submitForm('Guardar cambios', ['name' => 'Avena nueva']);
        self::assertResponseRedirects('/meal/favorites');
        $crawler = $client->followRedirect();
        self::assertStringContainsString(
            '«Avena nueva» actualizado.',
            $crawler->filter('body')->text(),
            'parameterized flash in Spanish',
        );
    }

    public function testSupplementsPagesRenderInUserLanguage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'locale@test.local');
        $user->setLocale(SupportedLocale::Spanish);
        $em->flush();
        $client->loginUser($user);

        // A window with no meals logged has no intake, an active supplement
        // included (ADR 0002), and short-circuits to the no-meals empty state; so
        // seed one meal plus one active supplement — real intake — to exercise the
        // translator-injected SupplementRecommendationService (gap labels +
        // suggestions) end to end.
        $meal = new \App\Entity\MealEntry();
        $meal->setUser($user);
        $meal->setMealType(\App\Enum\MealType::Lunch);
        // The user's wall-clock, not the entity's server-clock default (which is
        // already tomorrow for this user between 00:00 and 07:00 UTC).
        $meal->setLoggedAt(static::getContainer()->get(\App\Service\UserTime::class)->now($user));
        $meal->setNutritionData(\App\Entity\NutritionData::fromArray(['calories' => 500.0, 'protein' => 30.0]));
        $em->persist($meal);
        $supplement = new \App\Entity\Supplement();
        $supplement->setUser($user);
        $supplement->setName('Vitamina C diaria');
        $supplement->setNutrients(['vitaminC' => 80.0]);
        $em->persist($supplement);
        $em->flush();

        $crawler = $client->request('GET', '/supplements');
        self::assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Recomendaciones de suplementos', $body);
        self::assertStringContainsString('carencias de nutrientes', $body);
        self::assertStringContainsString('% IDR', $body);
        self::assertStringContainsString('nueces de Brasil', $body, 'supp_suggestion.* localizes');
        self::assertStringContainsString('Vitamina B12', $body, 'nutrient_full.* localizes');
        self::assertStringContainsString('Tus suplementos', $body);

        $crawler = $client->request('GET', '/supplements/scan');
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Escanear un suplemento', $body);
        self::assertStringContainsString('Leer etiqueta', $body);
        self::assertDoesNotMatchRegularExpression('/\bsupplement\.[a-z_]+/', $body);

        // Full flow: scan (mocked Claude) → Spanish confirm page → save flash
        // with the name param → delete flash.
        $img = sys_get_temp_dir() . '/locale-supp-' . uniqid() . '.jpg';
        $im = imagecreatetruecolor(64, 64);
        imagejpeg($im, $img);
        $upload = new \Symfony\Component\HttpFoundation\File\UploadedFile(
            $img,
            'label.jpg',
            'image/jpeg',
            null,
            true,
        );
        $client->submitForm('Leer etiqueta', [
            'supplement_photo_form[images]' => [$upload],
        ]);
        self::assertResponseRedirects('/supplements/confirm');
        $crawler = $client->followRedirect();
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Revisar suplemento', $body);
        self::assertStringContainsString('Macros por porción', $body);
        self::assertStringContainsString('Vitaminas y minerales por porción', $body);

        $client->submitForm('Guardar suplemento', [
            'name' => 'Multivitamínico X',
        ]);
        self::assertResponseRedirects('/supplements');
        $crawler = $client->followRedirect();
        self::assertStringContainsString(
            '«Multivitamínico X» se añadió a tus suplementos.',
            $crawler->filter('body')->text(),
        );

        // Delete via the card's own form (grabs its session-backed CSRF token).
        $supplement = $em->getRepository(\App\Entity\Supplement::class)->findOneBy([]);
        self::assertNotNull($supplement);
        $deleteForm = $crawler->filter('form[action$="/delete"]')->first();
        $client->request('POST', $deleteForm->attr('action'), [
            '_token' => $deleteForm->filter('input[name="_token"]')->attr('value'),
        ]);
        $crawler = $client->followRedirect();
        self::assertStringContainsString('Suplemento eliminado.', $crawler->filter('body')->text());
    }

    public function testSymptomsAndHealthPagesRenderInUserLanguage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'locale@test.local');
        $user->setLocale(SupportedLocale::Spanish);
        $em->flush();
        $client->loginUser($user);

        // /symptoms seeds the built-in defaults on first visit; add a custom one
        // and save a check-in so the flash + history exercise too.
        $crawler = $client->request('GET', '/symptoms');
        self::assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Gestionar síntomas', $body);
        self::assertStringContainsString('Dolor articular', $body, 'built-ins via symptom_label');
        self::assertStringContainsString('integrado', $body);
        self::assertDoesNotMatchRegularExpression('/\bsymptom_page\.[a-z_]+/', $body);

        $client->submitForm('Añadir', ['label' => 'Rodilla izquierda']);
        $crawler = $client->followRedirect();
        self::assertStringContainsString(
            '«Rodilla izquierda» se añadió a tus síntomas.',
            $crawler->filter('body')->text(),
        );

        // Enable the joint-pain built-in via its own toggle form, then the
        // check-in card renders: slider end labels + save + history row.
        $jointPain = $em->getRepository(\App\Entity\SymptomType::class)
            ->findOneBy(['typeKey' => 'joint_pain']);
        self::assertNotNull($jointPain);
        $toggleForm = $crawler
            ->filter('form[action$="/symptoms/types/' . $jointPain->getId() . '/toggle"] input[name="_token"]');
        self::assertGreaterThan(0, $toggleForm->count(), 'toggle form rendered');
        $client->request('POST', '/symptoms/types/' . $jointPain->getId() . '/toggle', [
            '_token' => $toggleForm->attr('value'),
        ]);
        $crawler = $client->followRedirect();
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Registro de hoy', $body);
        self::assertStringContainsString('Nada', $body, 'slider low end (higher-is-worse)');
        self::assertStringContainsString('Peor', $body, 'slider high end');

        $client->submitForm('Guardar el registro de hoy', [
            'level_' . $jointPain->getId() => '7',
        ]);
        $crawler = $client->followRedirect();
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Registro de síntomas guardado.', $body);
        self::assertStringContainsString('Dolor articular: 7', $body, 'history row via symptom_label');

        $crawler = $client->request('GET', '/health-metrics');
        self::assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Datos de salud', $body);
        self::assertStringContainsString('Aún no hay datos de salud para este periodo.', $body);

        // Seed one weight reading so the metric-card branch renders: transKey
        // label, bucketTransKey sublabel, and the readings ICU plural.
        $managedUser = $em->getRepository(User::class)->find($user->getId());
        $metric = new \App\Entity\HealthMetric();
        $metric->setUser($managedUser);
        $metric->setMetricType(\App\Enum\HealthMetricType::Weight);
        $metric->setValue(72.5);
        $metric->setRecordedAt(new \DateTimeImmutable('-1 day'));
        $em->persist($metric);
        $em->flush();

        $crawler = $client->request('GET', '/health-metrics');
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Peso', $body, 'metric label via transKey');
        self::assertStringContainsString('1 lectura', $body, 'readings ICU plural (singular)');
        self::assertStringContainsString('lecturas diarias', $body, 'bucketTransKey sublabel');

        $crawler = $client->request('GET', '/health-metrics/import');
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Importar datos de salud', $body);
        self::assertStringContainsString('Cómo exportar desde Apple Health', $body);
        self::assertDoesNotMatchRegularExpression('/\bhealth\.[a-z_]+\.[a-z_]+/', $body);
    }

    public function testGlucoseAndPredictPagesRenderInUserLanguage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'locale@test.local');
        $user->setLocale(SupportedLocale::Spanish);
        $em->flush();
        $client->loginUser($user);

        // Free user: glucose insights shows the Spanish Loop+ lock.
        $crawler = $client->request('GET', '/insights/glucose');
        self::assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Correlación de glucosa (MCG)', $body);
        self::assertStringContainsString('Mejorar a Loop+', $body);

        // Free user hitting Predict: redirected to pricing with the Spanish gate flash.
        $client->request('GET', '/insights/predict');
        self::assertResponseRedirects('/pricing');
        $crawler = $client->followRedirect();
        self::assertStringContainsString(
            'es una función de Loop+',
            $crawler->filter('body')->text(),
        );

        // Comp the user to Loop+ — the predict form renders in Spanish.
        $managedUser = $em->getRepository(User::class)->find($user->getId());
        $managedUser->setCompTier(\App\Enum\SubscriptionTier::LoopPlus);
        $em->flush();
        $crawler = $client->request('GET', '/insights/predict');
        self::assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('1. Describe tu escenario', $body);
        self::assertStringContainsString('Comer un favorito todos los días', $body);
        self::assertStringContainsString('Proyectar este escenario', $body);
        self::assertDoesNotMatchRegularExpression('/\bpredict\.[a-z_]+/', $body);
    }

    public function testConnectCgmWizardRendersInUserLanguage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'locale@test.local');
        $user->setLocale(SupportedLocale::Spanish);
        $em->flush();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/connect-cgm');
        self::assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Conecta tu Lingo o Stelo', $body);
        self::assertStringContainsString('Qué necesitas', $body);
        // HAE's own UI terms stay English inside translated prose — that's
        // what the user's phone actually shows.
        self::assertStringContainsString('Summarize Data', $body);
        self::assertStringContainsString('apaga', $body, 'the warning prose around it is Spanish');
        self::assertStringContainsString('Crear mi token', $body);
        self::assertStringContainsString('Mira cómo llega', $body);
        self::assertStringContainsString('Token creado', $body);
        self::assertDoesNotMatchRegularExpression('/\bcgm\.[a-z_]+/', $body);
        // Twig syntax leaking as literal page text = template-surgery residue
        // (caught by review on PR #152 — lint passes on it, so assert here).
        self::assertStringNotContainsString('|raw', $body);
        self::assertStringNotContainsString('|trans', $body);

        // Token creation flashes the copy-once card with the Spanish framing.
        $crawler = $client->submitForm('Crear mi token');
        $crawler = $client->followRedirect();
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Tu nuevo token — lo pegarás en el Paso 3', $body);
    }

    public function testDigestDeliveryRendersInRecipientLocale(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'locale@test.local');
        $user->setLocale(SupportedLocale::Spanish);
        $user->setNotifyNoMeals(true);
        $em->flush();

        // Run the digest for this user at their local evening — the cron path,
        // where there is no request locale at all.
        $service = static::getContainer()->get(\App\Service\NotificationService::class);
        $now = new \DateTimeImmutable('today 19:00', new \DateTimeZone($user->getTimezone()));
        $sent = $service->sendDueNotifications($user, $now);
        self::assertNotEmpty($sent, 'the no-meals notice should be due');

        // The in-app mirror was written in the RECIPIENT's locale.
        $em->clear();
        $notification = $em->getRepository(\App\Entity\Notification::class)
            ->findOneBy([], ['id' => 'DESC']);
        self::assertNotNull($notification);
        self::assertSame('Sin comidas registradas hoy', $notification->getTitle());
        self::assertStringContainsString('toma una foto', (string) $notification->getBody());
    }

    public function testMealDetailRendersInUserLanguage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'locale@test.local');
        $user->setLocale(SupportedLocale::Spanish);

        $meal = new \App\Entity\MealEntry();
        $meal->setUser($user);
        $meal->setMealType(\App\Enum\MealType::Breakfast);
        $meal->setLoggedAt(new \DateTimeImmutable('-2 hours'));
        $nutrition = new \App\Entity\NutritionData();
        $nutrition->setCalories(420.0);
        $nutrition->setProtein(22.0);
        $meal->setNutritionData($nutrition);
        $em->persist($meal);
        $em->persist($nutrition);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/meal/' . $meal->getId());

        self::assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Desayuno', $body, 'meal type via transKey');
        self::assertStringContainsString('Resumen nutricional', $body);
        self::assertStringContainsString('Proteína', $body, 'shared macro.* section');
        self::assertStringContainsString('Eliminar entrada', $body, 'delete button localized');
        self::assertStringNotContainsString('Delete Entry', $body);
    }

    public function testDatesAndNumbersFormatPerLocale(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'locale@test.local');
        $user->setLocale(SupportedLocale::Spanish);

        // A meal logged right now (user-local wall-clock) so the dashboard's
        // "today" totals have a 4-digit calorie figure to group.
        $meal = new \App\Entity\MealEntry();
        $meal->setUser($user);
        $meal->setMealType(\App\Enum\MealType::Lunch);
        $meal->setLoggedAt(new \DateTimeImmutable('now', new \DateTimeZone($user->getTimezone())));
        $nutrition = new \App\Entity\NutritionData();
        $nutrition->setCalories(1250.0);
        $nutrition->setProtein(30.0);
        $meal->setNutritionData($nutrition);
        $em->persist($meal);
        $em->persist($nutrition);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();

        // Phase 2 date formatting: the dashboard heading date renders with
        // Spanish field order/wording (local_date('yMMMMEEEEd')), e.g.
        // "sábado, 18 de julio de 2026" — computed here with the same ICU
        // machinery so the assertion doesn't rot with the calendar.
        $today = new \DateTimeImmutable('now', new \DateTimeZone($user->getTimezone()));
        $gen = new \IntlDatePatternGenerator('es');
        $fmt = new \IntlDateFormatter('es', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $today->getTimezone());
        $fmt->setPattern($gen->getBestPattern('yMMMMEEEEd'));
        self::assertStringContainsString((string) $fmt->format($today), $body);

        // Phase 2 number formatting: es groups thousands with a period.
        self::assertStringContainsString('1.250', $body, 'calorie total uses Spanish grouping');
        self::assertStringNotContainsString('1,250', $body);
    }

    public function testAnonymousLoginPageRendersInBrowserLanguage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        // No account, no session — the subscriber's Accept-Language path plus
        // the auth module's extracted strings, end to end.
        $client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'es-ES,es;q=0.9');
        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        self::assertSame('Inicia sesión', trim($crawler->filter('h2')->text()));
        self::assertStringContainsString('¿Olvidaste tu contraseña?', $body);
        self::assertStringContainsString('Crea una cuenta gratis', $body);
        self::assertSame('es-ES', $crawler->filter('html')->attr('lang'));
    }

    public function testRegistrationDetectsBrowserLanguage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'es-MX,es;q=0.9,en;q=0.5');
        $client->request('GET', '/register');
        // The register page itself already renders in Spanish (auth module is
        // extracted), so the submit button is the es catalog's label.
        $client->submitForm('Crear cuenta', [
            'registration_form[displayName]' => 'Mexico Person',
            'registration_form[email]' => 'mx@test.local',
            'registration_form[invitationCode]' => '',
            'registration_form[plainPassword][first]' => 'password123',
            'registration_form[plainPassword][second]' => 'password123',
        ]);

        self::assertResponseRedirects('/');
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'mx@test.local']);
        self::assertNotNull($user);
        self::assertSame(SupportedLocale::SpanishMexico, $user->getLocale());
    }

    public function testRegistrationWithUnsupportedLanguageDefaultsToEnglish(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'ja-JP,ja;q=0.9');
        $client->request('GET', '/register');
        $client->submitForm('Create Account', [
            'registration_form[displayName]' => 'Japanese Person',
            'registration_form[email]' => 'ja@test.local',
            'registration_form[invitationCode]' => '',
            'registration_form[plainPassword][first]' => 'password123',
            'registration_form[plainPassword][second]' => 'password123',
        ]);

        self::assertResponseRedirects('/');
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'ja@test.local']);
        self::assertNotNull($user);
        self::assertSame(SupportedLocale::English, $user->getLocale());
    }

    private function seedUser(EntityManagerInterface $em, string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Locale Tester');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setTimezone('America/New_York');
        // Verified so the unverified-email banner stays out of these flows.
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
