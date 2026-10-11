<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use App\Enum\UnitSystem;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UnitsFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('unitSystem', EnumType::class, [
            'class'        => UnitSystem::class,
            'choice_label' => fn (UnitSystem $u): string => $u->transKey(),
            'expanded'     => true,
            'label'        => 'settings.units.label',
            'help'         => 'settings.units.help',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
