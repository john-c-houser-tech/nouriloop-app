<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;

class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, [
                'label' => 'auth.register.display_name',
                'constraints' => [
                    new NotBlank(message: 'Please enter your name'),
                    new Length(min: 2, max: 100),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'auth.register.email',
                'constraints' => [
                    new NotBlank(message: 'Please enter your email'),
                    new Email(message: 'Please enter a valid email address.'),
                ],
            ])
            // Optional since the public soft-launch: blank → Free tier; a valid beta
            // code still grants the time-limited complimentary Loop+ (validated in the controller).
            ->add('invitationCode', TextType::class, [
                'label' => 'auth.register.coupon',
                'mapped' => false,
                'required' => false,
                'constraints' => [
                    new Length(max: 64),
                ],
            ])
            // Honeypot: humans never see it; the controller drops any filled submission.
            ->add('website', TextType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'auth.honeypot',
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'first_options' => ['label' => 'auth.register.password'],
                'second_options' => ['label' => 'auth.register.confirm_password'],
                'invalid_message' => 'Passwords must match.',
                'constraints' => [
                    new NotBlank(message: 'Please enter a password'),
                    new Length(min: 8, minMessage: 'Password must be at least {{ limit }} characters'),
                    // Blocks known-breached passwords via the k-anonymized HIBP API —
                    // more effective than composition rules (NIST 800-63B). Fails open
                    // on API outage; disabled in the test env (validator.yaml).
                    new NotCompromisedPassword(
                        skipOnError: true,
                        message: 'This password has appeared in a known data breach — '
                            . 'please choose a different one.',
                    ),
                ],
            ])
            // Populated by inline JS on the register template from the browser's
            // Intl timezone; the controller validates it against the IANA whitelist
            // and falls back to the default when blank/invalid (JS off, old browser).
            ->add('timezone', HiddenType::class, [
                'mapped' => false,
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
