<?php

declare(strict_types=1);

namespace App\Draft;

use App\Service\PhotoStaging;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Loads, stores and clears session drafts. It also owns their staged photos:
 * replacing a draft deletes the photos the new one no longer holds, and
 * clearing one deletes them all except those handed on to a saved record.
 */
final class DraftSession
{
    public function __construct(private readonly PhotoStaging $staging)
    {
    }

    /**
     * @template T of SessionDraft
     *
     * @param class-string<T> $type
     *
     * @return T|null
     */
    public function load(SessionInterface $session, string $type): ?SessionDraft
    {
        $data = $session->get($type::sessionKey());
        $draft = is_array($data) ? $type::fromArray($data) : null;
        if ($draft === null) {
            $session->remove($type::sessionKey());
        }

        return $draft;
    }

    /** Stores $draft, deleting any photos the draft it replaces held that it doesn't. */
    public function store(SessionInterface $session, SessionDraft $draft): void
    {
        $previous = $this->load($session, $draft::class);
        if ($previous !== null) {
            $this->staging->discard(array_diff($previous->stagedPaths(), $draft->stagedPaths()));
        }

        $session->set($draft::sessionKey(), $draft->toArray());
    }

    /**
     * Forgets the draft of $type and deletes its staged photos, except $keep
     * (photos that just became part of a saved record).
     *
     * @param class-string<SessionDraft> $type
     * @param list<string|null>          $keep
     */
    public function clear(SessionInterface $session, string $type, array $keep = []): void
    {
        $draft = $this->load($session, $type);
        if ($draft !== null) {
            $this->staging->discard(array_diff($draft->stagedPaths(), array_filter($keep)));
        }
        $session->remove($type::sessionKey());
    }
}
