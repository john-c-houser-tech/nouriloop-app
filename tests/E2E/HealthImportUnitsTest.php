<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use App\Enum\UnitSystem;
use App\Service\AppleHealthXmlImporter;
use App\Service\HealthMetricCsvImporter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Import-time unit handling against the real DB: the CSV importer (which has no
 * units of its own), the Apple Health XML importer (whose mmol/L label is
 * "mmol<180.1558800000541>/L"), and the `app:fix-glucose-units` repair for rows
 * stored before the fix. In-process, not Panther.
 */
final class HealthImportUnitsTest extends WebTestCase
{
    use DbResetTrait;

    public function testCsvRowFormatHonoursAUnitColumn(): void
    {
        $user = $this->seedUser(UnitSystem::Metric);

        $this->csv()->import($this->file(
            "date,type,value,unit\n"
            . "2026-06-15,weight,176.37,lb\n"
            . "2026-06-15,glucose,5.4,mmol/L\n"
            . "2026-06-16,glucose,104,mg/dL\n",
        ), $user);

        self::assertSame(
            ['blood_glucose' => [97.3, 104.0], 'weight' => [80.0]],
            $this->values(),
        );
    }

    public function testACsvDuplicateIsReportedAsSkippedNotImported(): void
    {
        $user = $this->seedUser(UnitSystem::Metric);

        $result = $this->csv()->import($this->file(
            "date,type,value\n"
            . "2026-06-15 08:00,weight,80\n"
            . "2026-06-15 08:00,weight,80\n",
        ), $user);

        self::assertSame(1, $result['imported']);
        self::assertSame(1, $result['skipped']);
        self::assertSame(['weight' => [80.0]], $this->values());
    }

    public function testCsvColumnHeadersCarryUnitsAndStepsImport(): void
    {
        $user = $this->seedUser(UnitSystem::Metric);

        $this->csv()->import($this->file(
            "date,weight (lb),glucose_mmol,steps\n"
            . "2026-06-15,176.37,6.1,8123\n",
        ), $user);

        self::assertSame(
            ['blood_glucose' => [109.91], 'steps' => [8123.0], 'weight' => [80.0]],
            $this->values(),
        );
    }

    public function testCsvUnlabelledWeightFollowsTheUsersUnitPreference(): void
    {
        $user = $this->seedUser(UnitSystem::Imperial);

        $this->csv()->import($this->file("date,weight\n2026-06-15,176.37\n"), $user);

        self::assertSame(['weight' => [80.0]], $this->values());
    }

    public function testAppleXmlMmolGlucoseAndFractionalBodyFatAreConverted(): void
    {
        $user = $this->seedUser(UnitSystem::Metric);
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <HealthData>
             <Record type="HKQuantityTypeIdentifierBloodGlucose" sourceName="Libre"
                     unit="mmol&lt;180.1558800000541&gt;/L" startDate="2026-06-15 08:00:00 -0700" value="5.4"/>
             <Record type="HKQuantityTypeIdentifierBloodGlucose" sourceName="Meter"
                     unit="mg/dL" startDate="2026-06-15 09:00:00 -0700" value="104"/>
             <Record type="HKQuantityTypeIdentifierBodyFatPercentage" sourceName="Scale"
                     unit="%" startDate="2026-06-15 07:00:00 -0700" value="0.25"/>
             <Record type="HKQuantityTypeIdentifierHeartRate" sourceName="Watch"
                     unit="count/min" startDate="2026-06-15 07:05:00 -0700" value="72.4567"/>
            </HealthData>
            XML;

        static::getContainer()->get(AppleHealthXmlImporter::class)->import($this->file($xml, 'xml'), $user);

        self::assertSame(
            ['blood_glucose' => [97.3, 104.0], 'body_fat_percentage' => [25.0], 'heart_rate' => [72.46]],
            $this->values(),
        );
        // values() rounds for comparison; the stored value itself is rounded too.
        self::assertSame(72.46, (float) $this->db()->fetchOne(
            "SELECT value FROM health_metric WHERE metric_type = 'heart_rate'",
        ));
    }

    public function testRepairCommandReportsThenConvertsOnlyPlausibleMmolRows(): void
    {
        $user = $this->seedUser(UnitSystem::Metric);
        $db = $this->db();
        foreach ([['08:00', 5.4], ['09:00', 110.0], ['10:00', 1.2]] as [$time, $value]) {
            $db->insert('health_metric', [
                'user_id' => $user->getId(),
                'metric_type' => 'blood_glucose',
                'value' => $value,
                'recorded_at' => '2026-06-15 ' . $time . ':00',
                'source' => 'legacy',
            ]);
        }

        // Report only: lists the one convertible row, notes the implausible one, writes nothing.
        $report = $this->runRepair([]);
        $report->assertCommandIsSuccessful();
        self::assertStringContainsString('units@test.local', $report->getDisplay());
        self::assertStringContainsString('left alone', $report->getDisplay());
        self::assertSame(['blood_glucose' => [1.2, 5.4, 110.0]], $this->values());

        // --apply converts it, and a second run finds nothing more to do.
        $this->runRepair(['--apply' => true])->assertCommandIsSuccessful();
        self::assertSame(['blood_glucose' => [1.2, 97.3, 110.0]], $this->values());
        $again = $this->runRepair(['--apply' => true]);
        self::assertStringContainsString('No glucose rows look like mmol/L', $again->getDisplay());
    }

    /** @param array<string, mixed> $opts */
    private function runRepair(array $opts): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:fix-glucose-units'));
        $tester->execute($opts);

        return $tester;
    }

    private function csv(): HealthMetricCsvImporter
    {
        return static::getContainer()->get(HealthMetricCsvImporter::class);
    }

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    /** @return array<string, list<float>> stored values by metric type, sorted */
    private function values(): array
    {
        $out = [];
        $rows = $this->db()->fetchAllAssociative(
            'SELECT metric_type, value FROM health_metric ORDER BY metric_type, value',
        );
        foreach ($rows as $row) {
            $out[$row['metric_type']][] = round((float) $row['value'], 2);
        }

        return $out;
    }

    private function file(string $contents, string $ext = 'csv'): string
    {
        $path = sys_get_temp_dir() . '/units-' . uniqid() . '.' . $ext;
        file_put_contents($path, $contents);

        return $path;
    }

    private function seedUser(UnitSystem $units): User
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('units@test.local');
        $user->setDisplayName('Units');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setUnitSystem($units);
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
