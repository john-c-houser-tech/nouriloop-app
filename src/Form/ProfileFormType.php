<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Settings ▸ Profile: display name + sign-in email. Everything is unmapped —
 * the email must NOT land on the User until the new address confirms it
 * (confirm-first, see SettingsController::profile), and the name only saves
 * when nothing else fails. current_password is enforced by the controller
 * only when the email actually changes (session-hijack guard); a name-only
 * edit never asks for it.
 */
class ProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, [
                'label' => 'settings.profile.name_label',
                // Same bounds as registration.
                'constraints' => [new NotBlank(), new Length(min: 2, max: 100)],
            ])
            ->add('email', EmailType::class, [
                'label' => 'settings.profile.email_label',
                // Length matches the entity column (180) — the Email constraint
                // alone accepts longer valid addresses, which would mail a
                // confirmation whose consume then dies at the DB write.
                'constraints' => [new NotBlank(), new Email(), new Length(max: 180)],
            ])
            ->add('current_password', PasswordType::class, [
                'label' => 'settings.profile.password_label',
                'required' => false,
                'attr' => ['autocomplete' => 'current-password'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // Unmapped by design — see the class docblock.
            'data_class' => null,
        ]);
    }
}
