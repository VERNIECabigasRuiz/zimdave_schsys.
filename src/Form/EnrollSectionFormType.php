<?php

namespace App\Form;

use App\Classes\Domain\Model\Section;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;

/** The transfer request form: a section picker from the given sections of the receiving school. */
class EnrollSectionFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('section', EntityType::class, [
            'label' => 'Section',
            'class' => Section::class,
            'choices' => $options['sections'],
            'choice_label' => static fn (Section $section) => $section->getLabel(),
            'placeholder' => 'Choose a section',
            'constraints' => [new NotNull(message: 'Choose a section.')],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'sections' => [],
        ]);
        $resolver->setAllowedTypes('sections', 'array');
    }
}
