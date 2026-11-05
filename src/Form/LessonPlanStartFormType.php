<?php

namespace App\Form;

use App\Entity\TeachingLoad;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;

/** Which class and which week a new lesson plan is for. Only the teacher's own loads are offered. */
class LessonPlanStartFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('load', EntityType::class, [
                'label' => 'Class',
                'class' => TeachingLoad::class,
                'choices' => $options['loads'],
                'choice_label' => static fn (TeachingLoad $l) => \sprintf('%s · %s %s', $l->getSubject()->getName(), $l->getSection()->getGradeLevel()?->label(), $l->getSection()->getName()),
                'placeholder' => 'Choose a class',
                'constraints' => [new NotNull(message: 'Choose a class.')],
            ])
            ->add('week', DateType::class, [
                'label' => 'Week',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => 'Any day of the week; the plan starts on its Monday.',
                'constraints' => [new NotNull(message: 'Choose a week.')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('loads')->setAllowedTypes('loads', 'array');
    }
}
