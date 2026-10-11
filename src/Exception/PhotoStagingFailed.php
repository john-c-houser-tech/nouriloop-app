<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Staging a batch of uploads failed; nothing from the batch is left on disk.
 * The two causes need different words for the user: a file that isn't an
 * acceptable image says so (the validator's own, translated message), while a
 * HEIC that couldn't be converted gets the HEIC message.
 */
final class PhotoStagingFailed extends \RuntimeException
{
    private function __construct(string $message, private readonly ?string $invalidFileMessage, ?\Throwable $previous)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function invalidFile(string $validatorMessage): self
    {
        return new self('Invalid upload: ' . $validatorMessage, $validatorMessage, null);
    }

    public static function conversionFailed(\Throwable $previous): self
    {
        return new self('HEIC conversion failed: ' . $previous->getMessage(), null, $previous);
    }

    /** What to tell the user when a file was rejected; null when conversion failed instead. */
    public function invalidFileMessage(): ?string
    {
        return $this->invalidFileMessage;
    }
}
