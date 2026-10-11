<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\HealthJsonImporter;
use App\Service\HealthJsonImportResolver;
use PHPUnit\Framework\TestCase;

final class HealthJsonImportResolverTest extends TestCase
{
    /**
     * @param array{imported:int,skipped:int,errors:string[]} $result
     */
    private function importer(
        string $key,
        bool $supports,
        array $result = ['imported' => 1, 'skipped' => 0, 'errors' => []],
    ): HealthJsonImporter {
        $importer = $this->createStub(HealthJsonImporter::class);
        $importer->method('key')->willReturn($key);
        $importer->method('label')->willReturn(ucfirst($key));
        $importer->method('supports')->willReturn($supports);
        $importer->method('import')->willReturn($result);

        return $importer;
    }

    public function testAutoDetectsBySupports(): void
    {
        $a        = $this->importer('a', false);
        $b        = $this->importer('b', true);
        $resolver = new HealthJsonImportResolver([$a, $b]);

        self::assertSame($b, $resolver->resolve([], null));
    }

    public function testExplicitKeyOverridesDetection(): void
    {
        $a        = $this->importer('a', false); // would not auto-detect
        $b        = $this->importer('b', true);
        $resolver = new HealthJsonImportResolver([$a, $b]);

        self::assertSame($a, $resolver->resolve(['anything' => 1], 'a'));
    }

    public function testUnknownExplicitKeyThrows(): void
    {
        $resolver = new HealthJsonImportResolver([$this->importer('a', true)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Unknown source app 'nope'");
        $resolver->resolve([], 'nope');
    }

    public function testUnrecognizedFormatThrows(): void
    {
        $resolver = new HealthJsonImportResolver([$this->importer('a', false)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not recognize');
        $resolver->resolve(['mystery' => true], null);
    }

    public function testImportDelegatesToResolvedImporter(): void
    {
        $resolver = new HealthJsonImportResolver([
            $this->importer('a', true, ['imported' => 5, 'skipped' => 2, 'errors' => []]),
        ]);

        $result = $resolver->import(['x' => 1], $this->createStub(User::class));

        self::assertSame(5, $result['imported']);
        self::assertSame(2, $result['skipped']);
    }

    public function testChoicesListsEveryImporter(): void
    {
        $resolver = new HealthJsonImportResolver([
            $this->importer('health-export', true),
            $this->importer('glucose-app', false),
        ]);

        self::assertSame(
            ['health-export' => 'Health-export', 'glucose-app' => 'Glucose-app'],
            $resolver->choices(),
        );
    }
}
