<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use App\Enum\SupportedLocale;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class LanguageFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('locale', EnumType::class, [
            'class'        => SupportedLocale::class,
            'choice_label' => fn (SupportedLocale $l): string => $l->label(),
            // Native names are universal on purpose — never translated
            // (see SupportedLocale::label).
            'choice_translation_domain' => false,
            'expanded' => true,
            'label'    => 'settings.language.label',
            'help'     => 'settings.language.help',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
