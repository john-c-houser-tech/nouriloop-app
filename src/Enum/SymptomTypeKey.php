<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Built-in symptom types seeded per user (each a row in symptom_type). Custom
 * user-defined symptoms are NOT in this enum — they live only as symptom_type
 * rows. All symptoms use a 0–10 scale; `higherIsBetter()` distinguishes positive
 * symptoms (energy, mental acuity) from severity ones (pain, bloating, …) and
 * drives the slider end-labels.
 */
enum SymptomTypeKey: string
{
    case JointPain = 'joint_pain';
    case JointStiffness = 'joint_stiffness';
    case Bloating = 'bloating';
    case Headache = 'headache';
    case Energy = 'energy';
    case MentalAcuity = 'mental_acuity';
    case Nausea = 'nausea';
    case Satiety = 'satiety';

    public function label(): string
    {
        return match ($this) {
            self::JointPain      => 'Joint pain',
            self::JointStiffness => 'Joint stiffness',
            self::Bloating       => 'Bloating / digestive',
            self::Headache       => 'Headache / migraine',
            self::Energy         => 'Energy level',
            self::MentalAcuity   => 'Mental acuity',
            self::Nausea         => 'Nausea',
            self::Satiety        => 'Fullness / satiety',
        };
    }

    /** Whether a higher 0–10 value is a good thing (energy, acuity) vs worse (severity). */
    public function higherIsBetter(): bool
    {
        return match ($this) {
            self::Energy, self::MentalAcuity, self::Satiety => true,
            default => false,
        };
    }

    /** Label for the 0 end of the slider. */
    public function lowLabel(): string
    {
        return $this->higherIsBetter() ? 'Low' : 'None';
    }

    /** Label for the 10 end of the slider. */
    public function highLabel(): string
    {
        return $this->higherIsBetter() ? 'High' : 'Worst';
    }

    /**
     * Built-in types in display order, for seeding and the manage UI.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [
            self::JointPain,
            self::JointStiffness,
            self::Bloating,
            self::Headache,
            self::Energy,
            self::MentalAcuity,
            self::Nausea,
            self::Satiety,
        ];
    }
}
