<?php

declare(strict_types=1);

namespace App\Draft;

/**
 * Something the user is part-way through that lives in the session: a draft,
 * a re-analysis proposal, or a parked retry (see CONTEXT.md). Stored as a plain
 * array under the same keys it always used, so a draft open across a deploy
 * still loads.
 */
interface SessionDraft
{
    public static function sessionKey(): string;

    /**
     * Rebuilds the draft from what the session held; null when it is malformed
     * or stale (e.g. its photos are gone), and the session entry is dropped.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?static;

    /** @return array<string, mixed> */
    public function toArray(): array;

    /**
     * The staged photos this draft holds on disk, so replacing or clearing it
     * can delete them.
     *
     * @return list<string>
     */
    public function stagedPaths(): array;
}
