<?php

declare(strict_types=1);

namespace App\Access\Presentation\Form;

use App\Access\Application\UseCase\Command\CreateAccountCommand;
use App\Access\Application\UseCase\Command\UpdateAccountCommand;
use App\Access\Domain\Model\User;
use App\Access\Presentation\Validation\UniqueUsername;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Creates an account (bound to CreateAccountCommand) or edits one (bound to UpdateAccountCommand).
 *
 * @extends AbstractType<CreateAccountCommand|UpdateAccountCommand>
 */
class UserAccountFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isNew = true === $options['is_new'];
        $learnerLrn = \is_string($options['learner_lrn']) ? $options['learner_lrn'] : null;

        // Students are created by enrolling a learner, so a new account can't be one
        $roles = $isNew ? array_diff_key(User::ROLE_LABELS, [User::ROLE_STUDENT => true]) : User::ROLE_LABELS;

        $learnerHelp = \sprintf('This account belongs to learner %s.', $learnerLrn);

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
                'disabled' => null !== $learnerLrn,
                'help' => null !== $learnerLrn ? $learnerHelp : null,
                'attr' => ['placeholder' => 'e.g. Juan Dela Cruz', 'autocomplete' => 'off'],
            ])
            ->add('username', TextType::class, [
                'label' => 'Username',
                'empty_data' => '',
                'disabled' => null !== $learnerLrn,
                'help' => null !== $learnerLrn
                    ? $learnerHelp
                    : 'Letters, numbers, dots, dashes and underscores. Used to sign in.',
                'attr' => ['autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false'],
            ]);

        if ($isNew) {
            $builder->add('temporaryPassword', TextType::class, [
                'label' => 'Temporary password',
                'empty_data' => '',
                'help' => 'Give this to the user. They must change it the first time they sign in.',
                'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Please enter a temporary password.'),
                    new Assert\Length(
                        min: 6,
                        max: 100,
                        minMessage: 'The temporary password must be at least {{ limit }} characters.',
                    ),
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
            'constraints' => [new UniqueUsername()],
            'is_new' => true,
            // Admins can't change their own role or deactivate themselves
            'lock_role' => false,
            // A learner's username is their LRN and their name comes from the learner record
            'learner_lrn' => null,
        ]);
        $resolver->setAllowedTypes('learner_lrn', ['null', 'string']);
        $resolver->setAllowedTypes('is_new', 'bool');
        $resolver->setAllowedTypes('lock_role', 'bool');
    }
}
