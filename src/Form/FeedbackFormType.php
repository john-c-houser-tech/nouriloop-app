<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Public "Send us feedback" form (linked from the footer). Name + email are
 * optional; feedback is required. Not persisted — emailed to the team. The
 * `website` field is a honeypot (see FeedbackController).
 */
class FeedbackFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Name (optional)',
                'required' => false,
                'constraints' => [new Length(max: 180)],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email (optional)',
                'required' => false,
                'constraints' => [
                    new Email(message: 'Please enter a valid email address.'),
                    new Length(max: 180),
                ],
            ])
            ->add('message', TextareaType::class, [
                'label' => 'Feedback',
                'constraints' => [
                    new NotBlank(message: 'Please enter your feedback.'),
                    new Length(max: 5000),
                ],
            ])
            // Honeypot: humans never see it; the controller drops any submission that fills it.
            ->add('website', TextType::class, [
                'required' => false,
                'label' => 'Leave this field blank',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
