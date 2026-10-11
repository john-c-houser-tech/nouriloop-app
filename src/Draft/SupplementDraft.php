<?php

declare(strict_types=1);

namespace App\Draft;

use App\Dto\StagedPhoto;
use App\Dto\SupplementAnalysis;

/** A scanned supplement label under review, before it is saved. */
final readonly class SupplementDraft implements SessionDraft
{
    /**
     * @param array<string, float> $nutrients per-serving amounts keyed by RDA key
     */
    public function __construct(
        public string $name,
        public string $servingSize,
        public float $confidence,
        public array $nutrients,
        public ?StagedPhoto $photo,
    ) {
    }

    public static function fromScan(SupplementAnalysis $scan, ?StagedPhoto $photo): self
    {
        return new self($scan->name, $scan->servingSize, $scan->confidence, $scan->nutrients, $photo);
    }

    public static function sessionKey(): string
    {
        return 'supplement_scan';
    }

    public static function fromArray(array $data): ?static
    {
        if (!isset($data['name']) || !is_array($data['nutrients'] ?? null)) {
            return null;
        }
        // image_path arrived with the typed draft; an older session has only the
        // web path, so its photo is left for the sweep rather than tracked.
        $photo = isset($data['image_web'])
            ? new StagedPhoto((string) ($data['image_path'] ?? ''), (string) $data['image_web'])
            : null;

        return new self(
            (string) $data['name'],
            (string) ($data['serving_size'] ?? ''),
            (float) ($data['confidence'] ?? 0.5),
            $data['nutrients'],
            $photo,
        );
    }

    public function toArray(): array
    {
        return [
            'name'         => $this->name,
            'serving_size' => $this->servingSize,
            'confidence'   => $this->confidence,
            'nutrients'    => $this->nutrients,
            'image_web'    => $this->photo?->webPath,
            'image_path'   => $this->photo?->path,
        ];
    }

    public function stagedPaths(): array
    {
        return $this->photo !== null && $this->photo->path !== '' ? [$this->photo->path] : [];
    }
}
