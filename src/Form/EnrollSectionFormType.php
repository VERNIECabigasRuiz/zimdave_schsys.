<?php

namespace App\Form;

use App\Entity\Learner;
use App\Entity\Section;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Valid;

/**
 * The enroll (or transfer request) form: a section picker from the given sections, and,
 * for a new LRN, the learner's details. An adviser's page passes no sections: their
 * own section is fixed.
 */
class EnrollSectionFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (null !== $options['learner']) {
            $builder->add('learner', LearnerFormType::class, [
                'data' => $options['learner'],
                'label' => false,
                'constraints' => [new Valid()],
            ]);
        }

        if (null !== $options['sections']) {
            $builder->add('section', EntityType::class, [
                'label' => 'Section',
                'class' => Section::class,
                'choices' => $options['sections'],
                'choice_label' => static fn (Section $section) => $section->getLabel(),
                'placeholder' => 'Choose a section',
                'constraints' => [new NotNull(message: 'Choose a section.')],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'learner' => null,
            'sections' => null,
        ]);
        $resolver->setAllowedTypes('learner', ['null', Learner::class]);
        $resolver->setAllowedTypes('sections', ['null', 'array']);
    }
}
