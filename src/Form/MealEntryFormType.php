<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\MealType;
use App\Service\FoodAnalysisService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Length;

class MealEntryFormType extends AbstractType
{
    /** Several shots of ONE meal (dish + label + packaging) — not a batch of meals. */
    public const MAX_PHOTOS = 3;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('mealType', EnumType::class, [
                'class' => MealType::class,
                'label' => 'meal.form.meal_type',
                'choice_label' => fn(MealType $type) => $type->transKey(),
            ])
            ->add('image', FileType::class, [
                'label' => 'meal.form.photo',
                'mapped' => false,
                // Optional at the field level: the controller requires either
                // photo(s) or a text description (the photo-free logging path).
                'required' => false,
                // Several shots of the SAME meal (the dish + its nutrition label)
                // are analyzed together in one Claude call.
                'multiple' => true,
                'constraints' => [
                    new Count(
                        max: self::MAX_PHOTOS,
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
                'label' => 'meal.form.describe',
                'mapped' => false,
                'required' => false,
                'attr' => ['rows' => 3],
                'constraints' => [new Length(max: 1000)],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'meal.form.notes',
                'required' => false,
                // Notes flow into the (billable) analysis prompt — bound them
                // here, with a defensive cap in FoodAnalysisService as backstop.
                'constraints' => [new Length(max: FoodAnalysisService::NOTES_MAX_LENGTH)],
                'attr' => ['rows' => 3, 'maxlength' => FoodAnalysisService::NOTES_MAX_LENGTH],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
