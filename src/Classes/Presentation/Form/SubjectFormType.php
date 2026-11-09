<?php

declare(strict_types=1);

namespace App\Classes\Presentation\Form;

use App\Classes\Application\UseCase\Command\SubjectDetails;
use App\Classes\Domain\Model\ShsTrack;
use App\Classes\Presentation\Validation\UniqueSubject;
use App\Classes\Presentation\Validation\ValidSubject;
use App\Schools\Domain\Model\GradeLevel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A subject, bound to a SubjectDetails command.
 *
 * @phpstan-type ParentChoice array{id: int, label: string, gradeLevel: ?GradeLevel, semester: ?int,
 *     track: ?ShsTrack, strand: ?string}
 *
 * @extends AbstractType<SubjectDetails>
 */
class SubjectFormType extends AbstractType
{
    public const SEMESTERS = SubjectDetails::SEMESTERS;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<GradeLevel> $grades */
        $grades = $options['grade_levels'];
        /** @var list<ParentChoice> $parentList */
        $parentList = $options['parents'];
        $parents = array_column($parentList, null, 'id');

        $builder
            ->add('gradeLevel', EnumType::class, [
                'label' => 'Grade level',
                'class' => GradeLevel::class,
                'choices' => $grades,
                'choice_label' => static fn (GradeLevel $level) => $level->label(),
                'placeholder' => \count($grades) > 1 ? 'Select grade level' : false,
                'attr' => ['data-action' => 'subject-form#toggle', 'data-subject-form-target' => 'grade'],
            ])
            ->add('code', TextType::class, [
                'label' => 'Subject code',
                'empty_data' => '',
                'attr' => ['placeholder' => 'e.g. MATH7', 'maxlength' => 20, 'autocapitalize' => 'characters'],
                'help' => 'Letters, numbers and dashes. Used on reports.',
            ])
            ->add('name', TextType::class, [
                'label' => 'Subject name',
                'empty_data' => '',
                'attr' => ['placeholder' => 'e.g. Mathematics'],
            ])
            ->add('parent', ChoiceType::class, [
                'label' => 'Component of',
                'property_path' => 'parentId',
                'choices' => array_keys($parents),
                'choice_label' => static fn (int $id): string => $parents[$id]['label'],
                'choice_attr' => static fn (int $id): array => ['data-grade' => $parents[$id]['gradeLevel']?->value],
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
                'help' => 'How many class periods a week. Leave empty for a subject with components; '
                    . 'set it on each component.',
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
        $builder->addEventListener(FormEvents::SUBMIT, static function (FormEvent $event) use ($parents): void {
            $subject = $event->getData();
            \assert($subject instanceof SubjectDetails);
            // Codes are stored in capitals, so they are checked that way; the field keeps what was typed
            $subject->code = mb_strtoupper(trim($subject->code));
            if (!$subject->gradeLevel?->isSeniorHigh()) {
                $subject->semester = null;
                $subject->track = null;
                $subject->strand = null;
            }
            $parent = null !== $subject->parentId ? $parents[$subject->parentId] ?? null : null;
            if (null !== $parent && $parent['gradeLevel'] === $subject->gradeLevel) {
                $subject->semester = $parent['semester'];
                $subject->track = $parent['track'];
                $subject->strand = $parent['strand'];
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'constraints' => [new ValidSubject(), new UniqueSubject()],
        ]);
        $resolver->setRequired(['grade_levels', 'parents']);
        $resolver->setAllowedTypes('grade_levels', GradeLevel::class . '[]');
        $resolver->setAllowedTypes('parents', 'array');
    }
}
