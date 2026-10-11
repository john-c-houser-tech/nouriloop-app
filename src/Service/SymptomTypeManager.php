<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SymptomType;
use App\Entity\User;
use App\Enum\SymptomTypeKey;
use App\Repository\SymptomTypeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Seeds the built-in symptom catalog for a user. Idempotent: inserts only the
 * built-in keys the user is missing, leaving existing rows (and their
 * enabled/disabled state) untouched. The migration backfills existing users;
 * this covers users created afterwards, called lazily from the Symptoms page.
 *
 * Built-ins are seeded **disabled** (opt-in) — the migration is the one place
 * that enables joint pain/stiffness for pre-existing loggers.
 */
class SymptomTypeManager
{
    public function __construct(
        private readonly SymptomTypeRepository $types,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function ensureDefaultsFor(User $user): void
    {
        $existing = $this->types->existingKeysFor($user);
        $missing = array_filter(
            SymptomTypeKey::ordered(),
            static fn (SymptomTypeKey $k): bool => !in_array($k->value, $existing, true),
        );

        if ($missing === []) {
            return;
        }

        foreach (SymptomTypeKey::ordered() as $position => $key) {
            if (!in_array($key, $missing, true)) {
                continue;
            }

            $type = (new SymptomType())
                ->setUser($user)
                ->setTypeKey($key->value)
                ->setLabel($key->label())
                ->setBuiltin(true)
                ->setEnabled(false)
                ->setHigherIsBetter($key->higherIsBetter())
                ->setPosition($position);

            $this->em->persist($type);
        }

        $this->em->flush();
    }

    /**
     * Enables the built-in GLP-1 check-ins (Nausea + Fullness/satiety) for the
     * user, seeding the full catalog first if needed, so opting into GLP-1 mode
     * starts those daily check-ins. Idempotent; does not touch any other
     * symptom's enabled state, and never disables (opting back out is manual on
     * the Symptoms page). Does not flush the enabled-state change — the caller
     * owns that (ensureDefaultsFor commits any newly-seeded rows separately).
     */
    public function enableGlp1Symptoms(User $user): void
    {
        $this->ensureDefaultsFor($user);

        foreach ([SymptomTypeKey::Nausea, SymptomTypeKey::Satiety] as $key) {
            $type = $this->types->findOneByUserAndKey($user, $key->value);
            if ($type !== null) {
                $type->setEnabled(true);
            }
        }
    }
}
