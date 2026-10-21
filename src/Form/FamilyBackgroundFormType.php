<?php

namespace App\Form;

use App\Entity\PersonalDataSheet;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** PDS II. Family Background, items 22–25. */
class FamilyBackgroundFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $text = static fn (string $label): array => ['label' => $label, 'required' => false];

        $builder
            ->add('spouseSurname', TextType::class, $text('Surname'))
            ->add('spouseFirstName', TextType::class, $text('First name'))
            ->add('spouseMiddleName', TextType::class, $text('Middle name'))
            ->add('spouseNameExtension', TextType::class, $text('Name extension'))
            ->add('spouseOccupation', TextType::class, $text('Occupation'))
            ->add('spouseEmployer', TextType::class, $text('Employer/Business name'))
            ->add('spouseBusinessAddress', TextType::class, $text('Business address'))
            ->add('spouseTelephone', TelType::class, $text('Telephone no.'))
            ->add('children', CollectionType::class, [
                'label' => false,
                'entry_type' => PdsChildFormType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
            ])
            ->add('fatherSurname', TextType::class, $text('Surname'))
            ->add('fatherFirstName', TextType::class, $text('First name'))
            ->add('fatherMiddleName', TextType::class, $text('Middle name'))
            ->add('fatherNameExtension', TextType::class, $text('Name extension'))
            ->add('motherMaidenSurname', TextType::class, $text('Surname'))
            ->add('motherFirstName', TextType::class, $text('First name'))
            ->add('motherMiddleName', TextType::class, $text('Middle name'));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PersonalDataSheet::class,
            'validation_groups' => [PersonalDataSheet::FAMILY],
        ]);
    }
}
