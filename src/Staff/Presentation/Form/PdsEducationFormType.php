<?php

declare(strict_types=1);

namespace App\Staff\Presentation\Form;

use App\Staff\Application\UseCase\Command\PdsEducationRow;
use App\Staff\Domain\Model\EducationLevel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One row of PDS item 26. Laid out on the card as:
 * level | school · course · from | to | year graduated · units earned | honors.
 *
 * @extends AbstractType<PdsEducationRow>
 */
class PdsEducationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $year = static fn (string $label, string $placeholder): array => [
            'label' => $label,
            'required' => false,
            'row_attr' => ['class' => 'field--third'],
            'attr' => ['placeholder' => $placeholder, 'min' => 1900, 'max' => 2100, 'inputmode' => 'numeric'],
        ];

        $builder
            ->add('level', EnumType::class, [
                'label' => 'Level',
                'class' => EducationLevel::class,
                'placeholder' => 'Select level',
                'choice_label' => static fn (EducationLevel $level) => $level->label(),
                'row_attr' => ['class' => 'field--third'],
            ])
            ->add('schoolName', TextType::class, [
                'label' => 'Name of school (write in full)',
                'row_attr' => ['class' => 'field--two-thirds'],
            ])
            ->add('course', TextType::class, [
                'label' => 'Basic education / degree / course (write in full)',
                'required' => false,
                'row_attr' => ['class' => 'field--wide'],
            ])
            ->add('yearFrom', IntegerType::class, $year('Attended from (year)', 'e.g. 2008'))
            ->add('yearTo', IntegerType::class, $year('Attended to (year)', 'e.g. 2012'))
            ->add('yearGraduated', IntegerType::class, $year('Year graduated', 'e.g. 2012'))
            ->add('highestLevel', TextType::class, [
                'label' => 'Highest level / units earned (if not graduated)',
                'required' => false,
                'attr' => ['placeholder' => 'e.g. 24 units'],
            ])
            ->add('honors', TextType::class, [
                'label' => 'Scholarship / academic honors received',
                'required' => false,
                'attr' => ['placeholder' => 'e.g. Cum Laude'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PdsEducationRow::class]);
    }
}
