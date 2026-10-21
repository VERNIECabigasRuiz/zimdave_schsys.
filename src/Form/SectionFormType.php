<?php

namespace App\Form;

use App\Entity\GradeLevel;
use App\Entity\School;
use App\Entity\SchoolAssignment;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\ShsTrack;
use App\Entity\User;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A class section. Grade levels are limited to the school type; the adviser list
 * holds only this school's teachers for the section's school year.
 */
class SectionFormType extends AbstractType
{
    public const STRANDS = ['STEM', 'ABM', 'HUMSS', 'GAS', 'ICT', 'Home Economics', 'Industrial Arts', 'Agri-Fishery Arts'];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var School $school */
        $school = $options['school'];
        /** @var SchoolYear $schoolYear */
        $schoolYear = $options['school_year'];

        $builder
            ->add('gradeLevel', EnumType::class, [
                'label' => 'Grade level',
                'class' => GradeLevel::class,
                'choices' => $school->getType()?->gradeLevels() ?? GradeLevel::cases(),
                'choice_label' => static fn (GradeLevel $level) => $level->label(),
                'placeholder' => 'Select grade level',
                'attr' => ['data-action' => 'section-form#toggle', 'data-section-form-target' => 'grade'],
            ])
            ->add('name', TextType::class, [
                'label' => 'Section name',
                'attr' => ['placeholder' => 'e.g. Sampaguita'],
            ])
            ->add('track', EnumType::class, [
                'label' => 'Track',
                'class' => ShsTrack::class,
                'choice_label' => static fn (ShsTrack $track) => $track->label(),
                'placeholder' => 'Select track',
                'required' => false,
                'row_attr' => ['data-section-form-target' => 'shs'],
            ])
            ->add('strand', TextType::class, [
                'label' => 'Strand / cluster',
                'required' => false,
                'attr' => ['list' => 'shs-strands', 'placeholder' => 'e.g. STEM'],
                'row_attr' => ['data-section-form-target' => 'shs'],
            ]);

        // Track and strand are hidden for Kinder–Grade 10; drop anything left in them
        $builder->addEventListener(FormEvents::SUBMIT, static function (FormEvent $event): void {
            $section = $event->getData();
            if ($section instanceof Section && !$section->getGradeLevel()?->isSeniorHigh()) {
                $section->setTrack(null)->setStrand(null);
            }
        });

        if ($options['with_adviser']) {
            $builder->add('adviser', EntityType::class, [
                'label' => 'Class adviser',
                'class' => User::class,
                'required' => false,
                'placeholder' => 'No adviser yet',
                'choice_label' => 'fullName',
                'query_builder' => static fn (EntityRepository $users) => $users->createQueryBuilder('u')
                    ->innerJoin(SchoolAssignment::class, 'a', 'WITH', 'a.user = u')
                    ->andWhere('a.school = :school')->setParameter('school', $school)
                    ->andWhere('a.schoolYear = :sy')->setParameter('sy', $schoolYear)
                    ->andWhere('a.role = :role')->setParameter('role', User::ROLE_TEACHER)
                    ->orderBy('u.fullName', 'ASC'),
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Section::class,
            'with_adviser' => true,
        ]);
        $resolver->setRequired(['school', 'school_year']);
        $resolver->setAllowedTypes('school', School::class);
        $resolver->setAllowedTypes('school_year', SchoolYear::class);
        $resolver->setAllowedTypes('with_adviser', 'bool');
    }
}
