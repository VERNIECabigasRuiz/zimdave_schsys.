<?php

namespace App\Form;

use App\Entity\PdsWorkExperience;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** One row of PDS item 28. */
class PdsWorkExperienceFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('positionTitle', TextType::class, ['label' => 'Position title (write in full)', 'attr' => ['placeholder' => 'e.g. Teacher I']])
            ->add('office', TextType::class, ['label' => 'Department / agency / office / company (write in full)'])
            ->add('dateFrom', DateType::class, ['label' => 'From', 'widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('dateTo', DateType::class, ['label' => 'To', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false])
            ->add('present', CheckboxType::class, ['label' => 'Present (current job)', 'required' => false])
            ->add('governmentService', CheckboxType::class, ['label' => 'Government service', 'required' => false])
            ->add('monthlySalary', TextType::class, [
                'label' => 'Monthly salary (₱)',
                'required' => false,
                'attr' => ['inputmode' => 'decimal', 'placeholder' => 'e.g. 30024'],
            ])
            ->add('salaryGrade', TextType::class, [
                'label' => 'Salary grade & step (00-0)',
                'required' => false,
                'attr' => ['placeholder' => 'e.g. 11-1', 'maxlength' => 4],
            ])
            ->add('appointmentStatus', TextType::class, [
                'label' => 'Status of appointment',
                'required' => false,
                'attr' => ['list' => 'appointment-statuses', 'placeholder' => 'e.g. Permanent'],
            ]);

        // Accept the salary the way people type it: "₱ 30,024.00" is saved as 30024.00
        $builder->get('monthlySalary')->addModelTransformer(new CallbackTransformer(
            static fn (?string $salary) => $salary,
            static fn (?string $typed) => null === $typed ? null : preg_replace('/^(?:₱|PHP|Php|P)\s*|[\s,]/u', '', trim($typed)),
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PdsWorkExperience::class]);
    }
}
