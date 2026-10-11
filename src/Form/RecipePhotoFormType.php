<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\File;

/**
 * Upload form for the recipe photo import: 1–5 photos of ONE recipe (front and
 * back of a card, a two-page spread, or screenshots). Mirrors
 * SupplementPhotoFormType, with a cap because each extra photo inflates one
 * billable vision request.
 */
class RecipePhotoFormType extends AbstractType
{
    public const MAX_PHOTOS = 5;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('images', FileType::class, [
            'label'    => 'recipe.scan.photos',
            'mapped'   => false,
            'multiple' => true,
            'required' => true,
            'constraints' => [
                new Count(
                    min: 1,
                    max: self::MAX_PHOTOS,
                    minMessage: 'Please upload at least one photo.',
                    maxMessage: 'Please upload at most {{ limit }} photos of one recipe.',
                ),
                new All([
                    new File(
                        maxSize: '20M',
                        mimeTypes: ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif'],
                        mimeTypesMessage: 'Please upload valid images (JPEG, PNG, WebP, or HEIC).',
                    ),
                ]),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
