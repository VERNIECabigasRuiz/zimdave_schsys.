<?php

namespace App\Form;

use App\Entity\Enrollment;
use App\Entity\LearningModality;
use App\Entity\Sf1Remark;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** What SF1 records about a learner's school year: learning modality and remarks. */
class EnrollmentRecordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('learningModality', EnumType::class, [
                'label' => 'Learning modality',
                'class' => LearningModality::class,
                'choice_label' => static fn (LearningModality $modality) => $modality->label(),
            ])
            ->add('remarkCode', EnumType::class, [
                'label' => 'Remark',
                'class' => Sf1Remark::class,
                'required' => false,
                'placeholder' => '— none —',
                'choice_label' => static fn (Sf1Remark $remark) => $remark->code().' – '.$remark->label(),
            ])
            ->add('remarkDetails', TextType::class, [
                'label' => 'Details',
                'required' => false,
                'help' => implode(' · ', array_map(static fn (Sf1Remark $r) => $r->code().': '.$r->requiredInfo(), Sf1Remark::cases())),
            ])
            ->add('remarkDate', DateType::class, [
                'label' => 'Effectivity date',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Enrollment::class]);
    }
}
