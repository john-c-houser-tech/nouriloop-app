<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * "Email me a sign-in link" request (GET|POST /login/link). Unmapped — the
 * controller only reads the email; nothing is persisted.
 */
class LoginLinkRequestFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'auth.link.email',
                'constraints' => [
                    new NotBlank(message: 'Please enter your email.'),
                    new Email(message: 'Please enter a valid email address.'),
                    new Length(max: 180),
                ],
            ])
            // Honeypot: a field humans never see but naive bots fill in. The
            // controller treats any value as spam and silently drops it.
            ->add('website', TextType::class, [
                'required' => false,
                'label' => 'auth.honeypot',
            ]);
    }
}
