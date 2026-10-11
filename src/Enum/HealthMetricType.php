<?php

declare(strict_types=1);

namespace App\Enum;

enum HealthMetricType: string
{
    case Weight = 'weight';
    case BloodGlucose = 'blood_glucose';
    case BloodPressureSystolic = 'blood_pressure_systolic';
    case BloodPressureDiastolic = 'blood_pressure_diastolic';
    case HeartRate = 'heart_rate';
    case BodyFatPercentage = 'body_fat_percentage';
    case Steps = 'steps';

    // Sleep stages stored separately to avoid double-counting.
    // New Apple Watch exports Core+Deep+REM+Awake; older ones export Asleep or InBed.
    case SleepInBed  = 'sleep_in_bed';   // HKCategoryValueSleepAnalysisInBed
    case SleepAsleep = 'sleep_asleep';   // HKCategoryValueSleepAnalysisAsleep (legacy)
    case SleepCore   = 'sleep_core';     // HKCategoryValueSleepAnalysisAsleepCore
    case SleepDeep   = 'sleep_deep';     // HKCategoryValueSleepAnalysisAsleepDeep
    case SleepREM    = 'sleep_rem';      // HKCategoryValueSleepAnalysisAsleepREM

    public function label(): string
    {
        return match ($this) {
            self::Weight                  => 'Weight',
            self::BloodGlucose            => 'Blood Glucose',
            self::BloodPressureSystolic   => 'Blood Pressure (Systolic)',
            self::BloodPressureDiastolic  => 'Blood Pressure (Diastolic)',
            self::HeartRate               => 'Heart Rate',
            self::BodyFatPercentage       => 'Body Fat %',
            self::Steps                   => 'Steps',
            self::SleepInBed              => 'Sleep (In Bed)',
            self::SleepAsleep             => 'Sleep',
            self::SleepCore               => 'Sleep (Core)',
            self::SleepDeep               => 'Sleep (Deep)',
            self::SleepREM                => 'Sleep (REM)',
        };
    }

    public function unit(): string
    {
        return match ($this) {
            self::Weight                                                     => 'kg',
            self::BloodGlucose                                               => 'mg/dL',
            self::BloodPressureSystolic, self::BloodPressureDiastolic        => 'mmHg',
            self::HeartRate                                                  => 'bpm',
            self::BodyFatPercentage                                          => '%',
            self::Steps                                                      => 'steps',
            self::SleepInBed, self::SleepAsleep,
            self::SleepCore, self::SleepDeep, self::SleepREM                => 'hr',
        };
    }

    public function isSleepType(): bool
    {
        return str_starts_with($this->value, 'sleep_');
    }

    /**
     * How this metric is summarized per time bucket (drives the SQL aggregate and
     * the card label): steps are a daily TOTAL, weight/body-fat are point READINGS,
     * and the rest (glucose, heart rate, blood pressure) are AVERAGES with a range.
     * Sleep types aren't shown via getAggregates (own SUM-based queries), so their
     * value here is unused.
     */
    public function summary(): MetricSummary
    {
        return match ($this) {
            self::Steps                            => MetricSummary::Total,
            self::Weight, self::BodyFatPercentage  => MetricSummary::Reading,
            default                                => MetricSummary::Average,
        };
    }

    /** Translation key for UI rendering (i18n); label() stays for PHP contexts. */
    public function transKey(): string
    {
        return 'metric.' . $this->value;
    }
}
