<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class UserAccountFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Students are created by enrolling a learner, so a new account can't be one
        $roles = $options['is_new'] ? array_diff_key(User::ROLE_LABELS, [User::ROLE_STUDENT => true]) : User::ROLE_LABELS;

        $builder
            ->add('role', ChoiceType::class, [
                'label' => 'Role',
                'choices' => array_flip($roles),
                'placeholder' => 'Choose a role',
                'constraints' => [new Assert\NotBlank(message: 'Please choose a role.')],
                'disabled' => $options['lock_role'],
            ])
            ->add('fullName', TextType::class, [
                'label' => 'Full name',
                'empty_data' => '',
                'attr' => ['placeholder' => 'e.g. Juan Dela Cruz', 'autocomplete' => 'off'],
            ])
            ->add('username', TextType::class, [
                'label' => 'Username',
                'empty_data' => '',
                'help' => 'Letters, numbers, dots, dashes and underscores. Used to sign in.',
                'attr' => ['autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false'],
            ]);

        if ($options['is_new']) {
            $builder->add('temporaryPassword', TextType::class, [
                'label' => 'Temporary password',
                'mapped' => false,
                'data' => $options['suggested_password'],
                'help' => 'Give this to the user. They must change it the first time they sign in.',
                'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Please enter a temporary password.'),
                    new Assert\Length(min: 6, max: 100, minMessage: 'The temporary password must be at least {{ limit }} characters.'),
                ],
            ]);
        } else {
            $builder->add('active', CheckboxType::class, [
                'label' => 'Account is active (can sign in)',
                'required' => false,
                'disabled' => $options['lock_role'],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'is_new' => true,
            'suggested_password' => null,
            // Admins can't change their own role or deactivate themselves
            'lock_role' => false,
        ]);
        $resolver->setAllowedTypes('is_new', 'bool');
        $resolver->setAllowedTypes('lock_role', 'bool');
    }
}
