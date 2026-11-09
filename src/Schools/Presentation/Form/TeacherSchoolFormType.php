<?php

declare(strict_types=1);

namespace App\Schools\Presentation\Form;

use App\Schools\Application\UseCase\Command\AssignTeacherSchoolCommand;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The school a teacher account teaches at (bound to AssignTeacherSchoolCommand).
 *
 * @extends AbstractType<AssignTeacherSchoolCommand>
 */
class TeacherSchoolFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<array{id: int, label: string}> $schools */
        $schools = $options['schools'];

        $builder->add('school', ChoiceType::class, [
            'property_path' => 'schoolId',
            'choices' => array_column($schools, 'id', 'label'),
            'placeholder' => 'Choose a school',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('schools')
            ->setAllowedTypes('schools', 'array');
    }
}
