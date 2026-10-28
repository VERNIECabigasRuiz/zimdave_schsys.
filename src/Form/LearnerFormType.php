<?php

namespace App\Form;

use App\Entity\Learner;
use App\Entity\SexAtBirth;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** A learner's details; the LRN field only for those who may change it. */
class LearnerFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['with_lrn']) {
            $builder->add('lrn', TextType::class, [
                'label' => 'LRN',
                'empty_data' => '',
                'help' => 'Learner Reference Number, 12 digits. It is also the learner\'s username.',
                'attr' => ['inputmode' => 'numeric', 'maxlength' => 12],
            ]);
        }

        $builder
            ->add('lastName', TextType::class, ['label' => 'Last name', 'empty_data' => ''])
            ->add('firstName', TextType::class, ['label' => 'First name', 'empty_data' => ''])
            ->add('middleName', TextType::class, ['label' => 'Middle name', 'required' => false])
            ->add('extension', TextType::class, [
                'label' => 'Extension',
                'required' => false,
                'attr' => ['placeholder' => 'Jr., Sr., III'],
            ])
            ->add('sex', EnumType::class, [
                'label' => 'Sex',
                'class' => SexAtBirth::class,
                'choice_label' => static fn (SexAtBirth $sex) => $sex->label(),
                'expanded' => true,
            ])
            ->add('birthDate', DateType::class, [
                'label' => 'Birthdate',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Learner::class,
            'with_lrn' => false,
        ]);
        $resolver->setAllowedTypes('with_lrn', 'bool');
    }
}
