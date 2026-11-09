<?php

declare(strict_types=1);

namespace App\Classes\Presentation\Form;

use App\Classes\Application\UseCase\Command\SectionDetails;
use App\Classes\Domain\Model\ShsTrack;
use App\Classes\Presentation\Validation\UniqueSection;
use App\Classes\Presentation\Validation\ValidSection;
use App\Schools\Domain\Model\GradeLevel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A class section, bound to a SectionDetails command. Grade levels are limited to the
 * school type; the adviser list holds only this school's teachers for the school year.
 *
 * @extends AbstractType<SectionDetails>
 */
class SectionFormType extends AbstractType
{
    public const STRANDS = [
        'STEM',
        'ABM',
        'HUMSS',
        'GAS',
        'ICT',
        'Home Economics',
        'Industrial Arts',
        'Agri-Fishery Arts',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<GradeLevel> $grades */
        $grades = $options['grades'];

        $builder
            ->add('gradeLevel', EnumType::class, [
                'label' => 'Grade level',
                'class' => GradeLevel::class,
                'choices' => $grades,
                'choice_label' => static fn (GradeLevel $level) => $level->label(),
                'placeholder' => 'Select grade level',
                'attr' => ['data-action' => 'section-form#toggle', 'data-section-form-target' => 'grade'],
            ])
            ->add('name', TextType::class, [
                'label' => 'Section name',
                'empty_data' => '',
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
            if ($section instanceof SectionDetails && !$section->gradeLevel?->isSeniorHigh()) {
                $section->track = null;
                $section->strand = null;
            }
        });

        if ($options['with_adviser']) {
            /** @var list<array{id: int, fullName: string}> $teachers */
            $teachers = $options['teachers'];
            $names = array_column($teachers, 'fullName', 'id');
            $builder->add('adviser', ChoiceType::class, [
                'label' => 'Class adviser',
                'property_path' => 'adviserId',
                'choices' => array_keys($names),
                'choice_label' => static fn (int $id): string => $names[$id],
                'required' => false,
                'placeholder' => 'No adviser yet',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'constraints' => [new ValidSection(), new UniqueSection()],
            'with_adviser' => true,
            'grades' => [],
            'teachers' => [],
        ]);
        $resolver->setAllowedTypes('with_adviser', 'bool');
        $resolver->setAllowedTypes('grades', GradeLevel::class . '[]');
        $resolver->setAllowedTypes('teachers', 'array');
    }
}
