<?php

namespace App\Form;

use App\Entity\GradeLevel;
use App\Entity\ShsTrack;
use App\Entity\Subject;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SubjectFormType extends AbstractType
{
    public const SEMESTERS = ['1st semester' => 1, '2nd semester' => 2];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('gradeLevel', EnumType::class, [
                'label' => 'Grade level',
                'class' => GradeLevel::class,
                'choices' => $options['grade_levels'],
                'choice_label' => static fn (GradeLevel $level) => $level->label(),
                'placeholder' => \count($options['grade_levels']) > 1 ? 'Select grade level' : false,
                'attr' => ['data-action' => 'subject-form#toggle', 'data-subject-form-target' => 'grade'],
            ])
            ->add('code', TextType::class, [
                'label' => 'Subject code',
                'attr' => ['placeholder' => 'e.g. MATH7', 'maxlength' => 20, 'autocapitalize' => 'characters'],
                'help' => 'Letters, numbers and dashes. Used on reports.',
            ])
            ->add('name', TextType::class, [
                'label' => 'Subject name',
                'attr' => ['placeholder' => 'e.g. Mathematics'],
            ])
            ->add('parent', EntityType::class, [
                'label' => 'Component of',
                'class' => Subject::class,
                'choices' => $options['parents'],
                'choice_label' => static fn (Subject $subject) => $subject->getLabel(),
                'choice_attr' => static fn (Subject $subject) => ['data-grade' => $subject->getGradeLevel()?->value],
                'placeholder' => 'Not a component',
                'required' => false,
                'help' => 'e.g. Music and Arts is a component of MAPEH. Its grade counts toward the subject\'s grade.',
                'attr' => ['data-action' => 'subject-form#toggle', 'data-subject-form-target' => 'parent'],
            ])
            ->add('position', IntegerType::class, [
                'label' => 'Order on report card',
                'attr' => ['min' => 1],
            ])
            ->add('meetingsPerWeek', IntegerType::class, [
                'label' => 'Meetings per week',
                'required' => false,
                'attr' => ['min' => 1, 'max' => 10],
                'help' => 'How many class periods a week. Leave empty for a subject with components; set it on each component.',
                'row_attr' => ['data-subject-form-target' => 'meetings'],
            ])
            ->add('semester', ChoiceType::class, [
                'choices' => self::SEMESTERS,
                'placeholder' => 'Select semester',
                'required' => false,
                'row_attr' => ['data-subject-form-target' => 'shs'],
            ])
            ->add('track', EnumType::class, [
                'label' => 'Only for track',
                'class' => ShsTrack::class,
                'choice_label' => static fn (ShsTrack $track) => $track->label(),
                'placeholder' => 'Every track',
                'required' => false,
                'row_attr' => ['data-subject-form-target' => 'shs'],
            ])
            ->add('strand', TextType::class, [
                'label' => 'Only for strand',
                'required' => false,
                'attr' => ['list' => 'shs-strands', 'placeholder' => 'Every strand'],
                'row_attr' => ['data-subject-form-target' => 'shs'],
            ]);

        // The SHS fields are hidden for K–10 and for components; drop or replace what is left in them
        $builder->addEventListener(FormEvents::SUBMIT, static function (FormEvent $event): void {
            $subject = $event->getData();
            if (!$subject instanceof Subject) {
                return;
            }
            if (!$subject->getGradeLevel()?->isSeniorHigh()) {
                $subject->setSemester(null)->setTrack(null)->setStrand(null);
            }
            if ($subject->getParent()?->getGradeLevel() === $subject->getGradeLevel()) {
                $subject->inheritFromParent();
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Subject::class]);
        $resolver->setRequired(['grade_levels', 'parents']);
        $resolver->setAllowedTypes('grade_levels', GradeLevel::class.'[]');
        $resolver->setAllowedTypes('parents', Subject::class.'[]');
    }
}
