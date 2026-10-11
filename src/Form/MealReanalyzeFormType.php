<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\FoodAnalysisService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Phase 3 of the meal-history plan: the inputs for re-running the AI on an
 * already-logged meal — a new/replacement photo, an edited description, or
 * both. Deliberately NOT bound to MealEntry: nothing here is saved on submit.
 * The submission buys one Claude call whose result is parked for review, and
 * only the diff screen's Apply writes to the meal.
 */
class MealReanalyzeFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('image', FileType::class, [
                'label' => 'meal.reanalyze.photo_label',
                'mapped' => false,
                // Optional at field level: the controller requires SOMETHING to
                // analyze — a new photo, the meal's own photo, or a description.
                'required' => false,
                'multiple' => true,
                'constraints' => [
                    new Count(
                        max: MealEntryFormType::MAX_PHOTOS,
                        maxMessage: 'Please upload at most {{ limit }} photos of one meal.',
                    ),
                    new All([
                        new File(
                            maxSize: '20M',
                            mimeTypes: [
                                'image/jpeg',
                                'image/png',
                                'image/gif',
                                'image/webp',
                                'image/heic',
                                'image/heif',
                            ],
                            mimeTypesMessage: 'Please upload a valid image (JPEG, PNG, WebP, or HEIC).',
                        ),
                    ]),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'meal.reanalyze.description_label',
                'mapped' => false,
                'required' => false,
                // Same bound as the log form's notes: this text is interpolated
                // into a billable prompt (and stored back onto the meal's notes
                // when the re-analysis is applied).
                'constraints' => [new Length(max: FoodAnalysisService::NOTES_MAX_LENGTH)],
                'attr' => ['rows' => 4, 'maxlength' => FoodAnalysisService::NOTES_MAX_LENGTH],
            ]);

        // Only offered when there IS a photo to replace. With no existing photo
        // an uploaded one simply becomes the meal's photo, so a choice would be
        // a control with one possible answer. Omitted rather than hidden — the
        // MealEditFormType include_nutrition lesson (form_end()'s leftover dump
        // would otherwise render it unstyled and controller-ignored).
        if ($options['has_photo']) {
            $builder->add('replace_photo', CheckboxType::class, [
                'label' => 'meal.reanalyze.replace_photo',
                'mapped' => false,
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['has_photo' => false]);
        $resolver->setAllowedTypes('has_photo', 'bool');
    }
}
