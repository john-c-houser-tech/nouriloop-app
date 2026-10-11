<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\Favorite;
use App\Entity\User;
use App\Enum\MealType;
use App\Service\UserTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The favorites picker's per-card meal-type select must preselect by the user's
 * local time of day, like the photo log page (Wave 1's E6 fixed the log form but
 * missed this sibling — every favorite log defaulted to Breakfast). Two users in
 * zones 12 hours apart always land in different defaultForHour bands, so the
 * pair assertion proves the default is really time-driven, not just Breakfast.
 */
final class FavoritePickerDefaultTest extends WebTestCase
{
    use DbResetTrait;

    public function testPickerPreselectsMealTypeByLocalHour(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $userTime = static::getContainer()->get(UserTime::class);

        $selected = [];
        foreach (['Etc/GMT' => 'utc@test.local', 'Etc/GMT+12' => 'utc12@test.local'] as $tz => $email) {
            $user = $this->seedUser($em, $email, $tz);
            $client->loginUser($user);

            $crawler = $client->request('GET', '/meal/favorites');
            self::assertResponseIsSuccessful();

            $value = $crawler->filter('select[name="meal_type"] option[selected]')->attr('value');
            $expected = MealType::defaultForHour($userTime->localHour($user));
            self::assertSame($expected->value, $value, "preselection follows $tz local hour");
            $selected[] = $value;
        }

        // 12 hours apart never falls in the same band, so a hardcoded Breakfast
        // default can't sneak back in unnoticed.
        self::assertNotSame($selected[0], $selected[1]);
    }

    private function seedUser(EntityManagerInterface $em, string $email, string $timezone): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Picker Person');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setTimezone($timezone);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $em->persist($user);

        $favorite = new Favorite();
        $favorite->setUser($user);
        $favorite->setName('Tuesday oatmeal');
        $favorite->setNutrients(['calories' => 320.0, 'protein' => 12.0, 'carbohydrates' => 45.0, 'fat' => 8.0]);
        $favorite->setIngredients([]);
        $favorite->setTokensPerAnalysis(0);
        $em->persist($favorite);

        $em->flush();

        return $user;
    }
}
