<?php

namespace App\Form;

use App\Entity\GradingPeriod;
use App\Entity\GradingSystem;
use App\Entity\SchoolYear;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SchoolYearFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'School year',
                'empty_data' => '',
                'attr' => ['placeholder' => 'e.g. 2026-2027'],
            ])
            ->add('startDate', DateType::class, [
                'label' => 'First day of classes',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('endDate', DateType::class, [
                'label' => 'Last day of classes',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('gradingSystem', EnumType::class, [
                'class' => GradingSystem::class,
                'label' => 'Grading periods',
                'expanded' => true,
                'choice_label' => static fn (GradingSystem $system) => $system->label(),
            ]);

        // Period dates exist only once the school year is saved
        if ($options['with_periods']) {
            // Rows are redrawn in the browser when terms/quarters is switched (grading-periods controller)
            $builder->add('gradingPeriods', CollectionType::class, [
                'entry_type' => GradingPeriodFormType::class,
                'entry_options' => [
                    'label' => false,
                    'empty_data' => static fn (FormInterface $row) => new GradingPeriod($row->getRoot()->getData(), (int) $row->getName() + 1),
                ],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'label' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SchoolYear::class,
            'with_periods' => false,
        ]);
        $resolver->setAllowedTypes('with_periods', 'bool');
    }
}
