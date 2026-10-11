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

class SupplementPhotoFormType extends AbstractType
{
    /**
     * Front, back, and one more side of a wrap-around label. Each photo is part of
     * one billable vision call, so the count is capped like the meal (3) and
     * recipe (5) forms.
     */
    public const MAX_PHOTOS = 3;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('images', FileType::class, [
            'label'    => 'supplement.scan.photos',
            'mapped'   => false,
            'multiple' => true,
            'required' => true,
            'constraints' => [
                new Count(
                    min: 1,
                    max: self::MAX_PHOTOS,
                    minMessage: 'Please upload at least one photo.',
                    maxMessage: 'Please upload at most {{ limit }} photos of one supplement label.',
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
