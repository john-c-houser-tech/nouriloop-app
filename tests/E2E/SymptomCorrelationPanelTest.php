<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\MealEntry;
use App\Entity\NutritionData;
use App\Entity\SymptomDay;
use App\Entity\SymptomEntry;
use App\Entity\SymptomType;
use App\Entity\User;
use App\Enum\MealType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Trends now renders one relationship panel per enabled+logged symptom, listing
 * only statistically significant correlations under each chart. Seeds a user whose
 * protein intake tracks their joint pain in lockstep over enough days to be
 * significant, then asserts the per-symptom tile, panel, and significant-correlation
 * row render. In-process WebTestCase against the real DB.
 */
final class SymptomCorrelationPanelTest extends WebTestCase
{
    use DbResetTrait;

    public function testPerSymptomPanelListsSignificantCorrelations(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $user = $this->seedUser($em);
        $type = $this->seedSymptomType($em, $user);

        // 10 days where protein and joint pain rise together (perfect correlation).
        for ($i = 1; $i <= 10; $i++) {
            $date = (new \DateTimeImmutable('today'))->modify("-{$i} day");
            $this->seedMeal($em, $user, $date, proteinGrams: (float) ($i * 12));
            $this->seedSymptom($em, $user, $type, $date, level: $i);
        }
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/trends?days=30');

        self::assertResponseIsSuccessful();

        $panel = 'symptom-' . $type->getId();
        // One tile and one detail panel for the symptom.
        self::assertGreaterThan(0, $crawler->filter('.insight-tile[data-panel="' . $panel . '"]')->count());
        $section = $crawler->filter('section[data-panel="' . $panel . '"]');
        self::assertCount(1, $section);
        self::assertCount(1, $section->filter('#symptomDietMultiples-' . $type->getId()));

        // The significant protein↔symptom correlation is listed.
        $text = $section->text();
        self::assertStringContainsString('Significant diet correlations', $text);
        self::assertStringContainsString('Protein', $text);
    }

    public function testCustomSymptomLabelWithMarkupIsEscapedInRawTranslations(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $user = $this->seedUser($em);
        // A custom (user-supplied) symptom whose label tries to inject markup —
        // it flows into |raw-rendered translations on the panel, so it must be
        // escaped as a parameter (caught by review on PR #146).
        $type = new SymptomType();
        $type->setUser($user);
        $type->setTypeKey('custom_evil');
        $type->setLabel('<img src=x onerror=alert(1)>knee');
        $type->setBuiltin(false);
        $type->setEnabled(true);
        $type->setHigherIsBetter(false);
        $type->setPosition(1);
        $em->persist($type);

        for ($i = 1; $i <= 10; $i++) {
            $date = (new \DateTimeImmutable('today'))->modify("-{$i} day");
            $this->seedMeal($em, $user, $date, proteinGrams: (float) ($i * 12));
            $this->seedSymptom($em, $user, $type, $date, level: $i);
        }
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/trends?days=30');

        self::assertResponseIsSuccessful();
        $html = $crawler->html();
        // The raw label legitimately appears inside json_encode'd <script> data
        // (inert: consumed via textContent, and json_encode escapes the slash in
        // any </script> breakout attempt). Outside script blocks the literal tag
        // must never survive into markup…
        $noScripts = preg_replace('#<script\b[^>]*>.*?</script>#s', '', $html);
        self::assertStringNotContainsString('<img src=x onerror=', $noScripts);
        // …while the label text still shows (escaped) in the panel prose.
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;knee', $html);
    }

    private function seedUser(EntityManagerInterface $em): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('corr@test.local');
        $user->setDisplayName('Corr Tester');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setTimezone('America/New_York');
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function seedSymptomType(EntityManagerInterface $em, User $user): SymptomType
    {
        $type = new SymptomType();
        $type->setUser($user);
        $type->setTypeKey('joint_pain');
        $type->setLabel('Joint pain');
        $type->setBuiltin(true);
        $type->setEnabled(true);
        $type->setHigherIsBetter(false);
        $type->setPosition(0);
        $em->persist($type);
        $em->flush();

        return $type;
    }

    private function seedMeal(
        EntityManagerInterface $em,
        User $user,
        \DateTimeImmutable $date,
        float $proteinGrams,
    ): void {
        $nutrition = new NutritionData();
        $nutrition->setCalories(500.0);
        $nutrition->setProtein($proteinGrams);
        $nutrition->setCarbohydrates(40.0);
        $nutrition->setFat(15.0);
        $nutrition->setFiber(5.0);

        $meal = new MealEntry();
        $meal->setUser($user);
        $meal->setMealType(MealType::Lunch);
        $meal->setLoggedAt($date->setTime(12, 0));
        $meal->setIngredients([['name' => 'test food']]);
        $meal->setNutritionData($nutrition);
        $em->persist($meal);
    }

    private function seedSymptom(
        EntityManagerInterface $em,
        User $user,
        SymptomType $type,
        \DateTimeImmutable $date,
        int $level,
    ): void {
        $day = new SymptomDay();
        $day->setUser($user);
        $day->setLoggedDate($date->setTime(0, 0));

        $entry = new SymptomEntry();
        $entry->setSymptomType($type);
        $entry->setLevel($level);
        $day->addEntry($entry);

        $em->persist($day);
        $em->persist($entry);
    }
}
