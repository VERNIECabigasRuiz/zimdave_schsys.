<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** A period's weighing sheet: the date of weighing and a line per learner on the roster. */
class NutritionSheetFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('weighedOn', DateType::class, ['label' => 'Date of weighing', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => true])
            ->add('entries', CollectionType::class, ['entry_type' => NutritionEntryFormType::class, 'label' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => NutritionSheet::class]);
    }
}
