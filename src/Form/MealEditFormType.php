<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\MealEntry;
use App\Enum\MealType;
use App\Service\FoodAnalysisService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\LessThan;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\PositiveOrZero;

/**
 * Phases 1+2 of the meal-history plan: type/notes/time (Phase 1) plus the
 * cheap numeric edits (Phase 2) — manual nutrient corrections, a one-shot
 * portion rescale, and a full date+time field so a meal can move to a
 * different day (behind the controller's confirmation guard). Everything
 * downstream reads meal rows live, so none of this needs a recompute step.
 * Photos and re-analysis are Phase 3 and stay out.
 */
class MealEditFormType extends AbstractType
{
    /**
     * The manually-editable nutrients: the headline macros plus the three
     * secondary values most often mislabeled (and most consequential for the
     * digest/goal thresholds). Micros stay AI-only — same posture as the
     * favorite edit. Keys double as nutrient_full.* label keys and
     * NutritionData getter/setter stems.
     */
    public const NUTRIENT_FIELDS = ['calories', 'protein', 'carbohydrates', 'fat', 'fiber', 'sugar', 'sodium'];

    /** Sanity ceiling for a single meal's nutrient values (kcal or g/mg). */
    private const NUTRIENT_MAX = 100000;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('mealType', EnumType::class, [
                'class' => MealType::class,
                'label' => 'meal.form.meal_type',
                'choice_label' => fn (MealType $type) => $type->transKey(),
            ])
            ->add('logged_at', DateTimeType::class, [
                'label' => 'meal.edit.when_label',
                // Unmapped: the controller re-parses the string in the user's
                // timezone (parseLoggedAt idiom) and guards future instants +
                // cross-day moves itself.
                'mapped' => false,
                'widget' => 'single_text',
                'input' => 'string',
                'constraints' => [new NotBlank()],
            ])
            ->add('confirm_day_change', CheckboxType::class, [
                'label' => 'meal.edit.day_change_confirm',
                // Only required when the posted date differs from the stored
                // one — enforced server-side in the controller, revealed
                // client-side by the template's inline JS.
                'mapped' => false,
                'required' => false,
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'meal.form.notes',
                'required' => false,
                // Same bound as the log form: notes flow into the (billable)
                // analysis prompt if the meal is ever re-analyzed (Phase 3).
                'constraints' => [new Length(max: FoodAnalysisService::NOTES_MAX_LENGTH)],
                'attr' => ['rows' => 3, 'maxlength' => FoodAnalysisService::NOTES_MAX_LENGTH],
            ]);

        // Omitted entirely (not hidden) for a meal with no NutritionData row —
        // otherwise form_end()'s leftover dump would render the unstyled,
        // controller-ignored fields (Copilot catch on PR #177; the
        // NotificationPreferencesFormType include_sms idiom).
        if ($options['include_nutrition']) {
            $builder->add('portions', NumberType::class, [
                'label' => 'meal.edit.portion_label',
                // A one-shot multiplier over the whole stored meal (nutrition
                // + numeric ingredient amounts), applied by the controller via
                // NutritionData::scaleBy() + PortionScaler. Blank = no rescale.
                'mapped' => false,
                'required' => false,
                'html5' => true,
                'attr' => ['step' => 'any', 'min' => '0.01', 'max' => '100'],
                'constraints' => [new Positive(), new LessThan(101)],
            ]);

            foreach (self::NUTRIENT_FIELDS as $field) {
                $builder->add($field, NumberType::class, [
                    'label' => 'nutrient_full.' . $field,
                    // Unmapped + prefilled by the controller; blank keeps the
                    // stored value (never nulls it), so an untouched form is a
                    // no-op even if the browser drops a prefill.
                    'mapped' => false,
                    'required' => false,
                    'html5' => true,
                    'attr' => ['step' => 'any', 'min' => '0'],
                    'constraints' => [new PositiveOrZero(), new LessThan(self::NUTRIENT_MAX)],
                ]);
            }
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MealEntry::class,
            'include_nutrition' => true,
        ]);
        $resolver->setAllowedTypes('include_nutrition', 'bool');
    }
}
