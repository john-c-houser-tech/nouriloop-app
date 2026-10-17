<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use App\Enum\NotificationChannel;
use App\Hosting\Brand;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Regex;

class NotificationPreferencesFormType extends AbstractType
{
    public function __construct(
        private readonly Brand $brand,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('notificationChannel', EnumType::class, [
                'class'        => NotificationChannel::class,
                'choice_label' => fn (NotificationChannel $c): string => $c->transKey(),
                'expanded'     => true,
                'label'        => 'settings.notifications.channel',
            ]);

        // The SMS opt-in controls (phone + TCPA consent) render only for users whose
        // tier includes SMS sends (`sms`, Loop+ only) — the template shows an
        // upsell hint instead. The fields are OMITTED (not hidden) so an unrendered,
        // unsubmitted phoneNumber can't null out a stored number on save. The channel
        // radios above deliberately stay: the stored preference is shared and never
        // mutated by tier — gating happens per-feature at send time.
        if ($options['include_sms']) {
            $builder
                ->add('phoneNumber', TelType::class, [
                    'required' => false,
                    'label'    => 'settings.notifications.phone',
                    'attr'     => ['placeholder' => '+15555550123'],
                    'help'     => 'settings.notifications.phone_help',
                    'constraints' => [
                        new Length(max: 32),
                        new Regex(
                            pattern: '/^\+[1-9]\d{6,14}$/',
                            message: 'Enter a valid number in E.164 format, e.g. +15555550123.',
                            match: true,
                        ),
                    ],
                ])
                // ⚠️ i18n: the consent label below deliberately stays ENGLISH — TCPA
                // consent language translation is a parked compliance decision (see the
                // i18n plan's "Parked decisions"). Translate only with that sign-off.
                ->add('smsConsent', CheckboxType::class, [
                    'required' => false,
                    'mapped'   => false,
                    'label'    => 'I agree to receive recurring automated text messages '
                        . 'from ' . $this->brand->name() . ' at the number above. Consent is not a condition of '
                        . 'use; message and data rates may apply; reply STOP to cancel or HELP '
                        . 'for help.',
                ]);
        }

        $builder
            ->add('notifyNoMeals', CheckboxType::class, [
                'required' => false,
                'label'    => 'settings.notifications.no_meals',
            ])
            ->add('notifyStaleMetrics', CheckboxType::class, [
                'required' => false,
                'label'    => 'settings.notifications.stale',
            ])
            ->add('notifyCalorieRange', CheckboxType::class, [
                'required' => false,
                'label'    => 'settings.notifications.calories',
            ])
            ->add('notifySupplements', CheckboxType::class, [
                'required' => false,
                'label'    => 'settings.notifications.supplements',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'  => User::class,
            'include_sms' => true,
        ]);
        $resolver->setAllowedTypes('include_sms', 'bool');
    }
}
