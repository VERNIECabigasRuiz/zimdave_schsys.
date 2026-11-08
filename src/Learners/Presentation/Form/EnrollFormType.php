<?php

declare(strict_types=1);

namespace App\Learners\Presentation\Form;

use App\Learners\Application\UseCase\Command\EnrollLearnerCommand;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Valid;

/**
 * The enroll form: a section picker from the given sections and, for a new LRN, the learner's details.
 * An adviser's page passes no sections: their own section is fixed.
 *
 * @extends AbstractType<EnrollLearnerCommand>
 */
final class EnrollFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $command = $options['data'];
        \assert($command instanceof EnrollLearnerCommand);
        if (null !== $command->details) {
            $builder->add('learner', LearnerFormType::class, [
                'property_path' => 'details',
                'label' => false,
                'constraints' => [new Valid()],
            ]);
        }
        if (null !== $options['sections']) {
            $builder->add('section', ChoiceType::class, [
                'label' => 'Section',
                'property_path' => 'sectionId',
                'choices' => $options['sections'],
                'placeholder' => 'Choose a section',
                'constraints' => [new NotNull(message: 'Choose a section.')],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EnrollLearnerCommand::class,
            'sections' => null,
        ]);
        $resolver->setAllowedTypes('sections', ['null', 'array']);
    }

    /** The same field names as the transfer request form the enroll page also shows. */
    public function getBlockPrefix(): string
    {
        return 'enroll_section_form';
    }
}
