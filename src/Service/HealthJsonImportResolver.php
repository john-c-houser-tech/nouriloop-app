<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Routes a decoded health-export payload to the right {@see HealthJsonImporter}.
 *
 * Default behavior is auto-detection (each importer's supports()); callers may
 * force a specific app by key (the optional override), which is what the web
 * "Source app" dropdown, the API `?app=` query and the CLI `--app=` flag feed in.
 */
final class HealthJsonImportResolver
{
    /**
     * @param iterable<HealthJsonImporter> $importers
     */
    public function __construct(
        #[AutowireIterator('app.health_json_importer')]
        private readonly iterable $importers,
    ) {
    }

    /**
     * Reads, decodes and imports a JSON file (the web-import path).
     *
     * @return array{imported: int, skipped: int, errors: string[]}
     */
    public function importFile(string $filePath, User $user, ?string $appKey = null): array
    {
        if (!is_readable($filePath)) {
            throw new \RuntimeException("File not readable: {$filePath}");
        }

        $raw = file_get_contents($filePath);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read file: {$filePath}");
        }

        return $this->import($this->decode($raw), $user, $appKey);
    }

    /**
     * Imports an already-decoded payload (the API path).
     *
     * @param  array<mixed> $data
     * @return array{imported: int, skipped: int, errors: string[]}
     */
    public function import(array $data, User $user, ?string $appKey = null): array
    {
        return $this->resolve($data, $appKey)->import($data, $user);
    }

    /**
     * Picks the importer: by explicit key when given, else by auto-detection.
     *
     * @param array<mixed> $data
     */
    public function resolve(array $data, ?string $appKey = null): HealthJsonImporter
    {
        if ($appKey !== null && $appKey !== '') {
            foreach ($this->importers as $importer) {
                if ($importer->key() === $appKey) {
                    return $importer;
                }
            }

            throw new \RuntimeException(sprintf("Unknown source app '%s'.", $appKey));
        }

        foreach ($this->importers as $importer) {
            if ($importer->supports($data)) {
                return $importer;
            }
        }

        throw new \RuntimeException(
            'Could not recognize the health-export format — choose the source app explicitly.',
        );
    }

    /**
     * key => label for every registered importer (for the UI dropdown).
     *
     * @return array<string, string>
     */
    public function choices(): array
    {
        $choices = [];
        foreach ($this->importers as $importer) {
            $choices[$importer->key()] = $importer->label();
        }

        return $choices;
    }

    /**
     * @return array<mixed>
     */
    private function decode(string $raw): array
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('File is not valid JSON.');
        }

        return $data;
    }
}
