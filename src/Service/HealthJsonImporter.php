<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;

/**
 * One implementation per supported health-export app. Each recognises its own
 * JSON envelope and maps it to NouriLoop metric rows. New apps are added by
 * dropping in a class that implements this interface — the resolver, the web
 * import, the API route and the CLI all pick it up automatically (it is
 * auto-tagged via `_instanceof` in services.yaml).
 */
interface HealthJsonImporter
{
    /** Stable machine key used for the explicit-override (e.g. 'health-export'). */
    public function key(): string;

    /** Human label for the "Source app" dropdown. */
    public function label(): string;

    /**
     * Whether this importer recognises the decoded payload as its own format.
     *
     * @param array<mixed> $data
     */
    public function supports(array $data): bool;

    /**
     * Maps and persists the payload, returning per-run counts.
     *
     * @param  array<mixed> $data
     * @return array{imported: int, skipped: int, errors: string[]}
     */
    public function import(array $data, User $user): array;
}
