<?php

declare(strict_types=1);

namespace App\Draft;

/**
 * A meal log whose analysis failed, parked with its staged photos so retrying
 * costs one click instead of a re-upload. Only photo analyses park: a failed
 * described meal re-renders the form with the text intact.
 */
final readonly class RetryParking implements SessionDraft
{
    /**
     * @param non-empty-list<string> $imagePaths on-disk paths, first is the meal's photo
     */
    public function __construct(
        public array $imagePaths,
        public string $imageWebPath,
        public string $mealType,
        public ?string $notes,
        public string $describe,
    ) {
    }

    public static function sessionKey(): string
    {
        return 'meal_analysis_retry';
    }

    /** Null when nothing is parked or any parked photo has since vanished from disk. */
    public static function fromArray(array $data): ?static
    {
        $paths = array_values((array) ($data['image_paths'] ?? []));
        if ($paths === [] || array_filter($paths, 'is_file') !== $paths || !isset($data['image_web_path'])) {
            return null;
        }

        return new self(
            $paths,
            (string) $data['image_web_path'],
            (string) ($data['meal_type'] ?? ''),
            isset($data['notes']) ? (string) $data['notes'] : null,
            (string) ($data['describe'] ?? ''),
        );
    }

    public function toArray(): array
    {
        return [
            'image_paths'    => $this->imagePaths,
            'image_web_path' => $this->imageWebPath,
            'meal_type'      => $this->mealType,
            'notes'          => $this->notes,
            'describe'       => $this->describe,
        ];
    }

    public function stagedPaths(): array
    {
        return $this->imagePaths;
    }
}
