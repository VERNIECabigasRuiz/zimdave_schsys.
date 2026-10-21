<?php

namespace App\Form;

use App\Entity\Plan;
use App\Entity\School;
use App\Entity\SchoolType;
use App\Repository\PlanRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SchoolFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('schoolId', TextType::class, [
                'label' => 'DepEd School ID',
                'empty_data' => '',
                'attr' => ['placeholder' => 'e.g. 303456', 'inputmode' => 'numeric', 'maxlength' => 6],
            ])
            ->add('name', TextType::class, [
                'label' => 'School name',
                'empty_data' => '',
            ])
            ->add('type', EnumType::class, [
                'class' => SchoolType::class,
                'label' => 'School type',
                'placeholder' => 'Select type',
                'choice_label' => static fn (SchoolType $type) => $type->label(),
            ])
            ->add('region', TextType::class, [
                'empty_data' => '',
                'attr' => ['placeholder' => 'e.g. Region VII'],
            ])
            ->add('division', TextType::class, [
                'empty_data' => '',
                'attr' => ['placeholder' => 'e.g. Mandaue City'],
            ])
            ->add('district', TextType::class, [
                'required' => false,
            ])
            ->add('address', TextType::class, [
                'required' => false,
            ])
            ->add('plan', EntityType::class, [
                'class' => Plan::class,
                'choice_label' => static fn (Plan $plan) => \sprintf(
                    '%s — %d teacher(s), %s students per seat',
                    $plan->getName(),
                    $plan->getTeachersPerSeat(),
                    number_format($plan->getStudentsPerSeat()),
                ),
                'query_builder' => static fn (PlanRepository $plans) => $plans->createQueryBuilder('p')->orderBy('p.name'),
                'placeholder' => 'Choose a plan',
            ])
            ->add('seats', IntegerType::class, [
                'help' => 'Limits are the plan numbers times the seats, e.g. 2 Individual seats = 2 teachers.',
                'attr' => ['min' => 1],
            ])
            ->add('planValidUntil', DateType::class, [
                'label' => 'Plan valid until',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'help' => 'Last paid day. Leave empty if the plan never expires.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => School::class]);
    }
}
