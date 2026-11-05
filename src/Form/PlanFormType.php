<?php

namespace App\Form;

use App\Entity\Plan;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PlanFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Plan name',
                'empty_data' => '',
                'attr' => ['placeholder' => 'e.g. Standard'],
            ])
            ->add('teachersPerSeat', IntegerType::class, [
                'label' => 'Teachers per seat',
                'attr' => ['min' => 1],
            ])
            ->add('studentsPerSeat', IntegerType::class, [
                'label' => 'Students per seat',
                'attr' => ['min' => 1],
            ])
            ->add('includesPrincipal', CheckboxType::class, [
                'label' => 'Includes a principal',
                'required' => false,
                'help' => 'Turn off for teacher-only plans such as Individual.',
            ])
            ->add('aiDraftsPerTeacherMonthly', IntegerType::class, [
                'label' => 'AI lesson plan drafts per teacher per month',
                'empty_data' => '0',
                'help' => '0 turns the AI off for this plan.',
                'attr' => ['min' => 0],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Plan::class]);
    }
}
