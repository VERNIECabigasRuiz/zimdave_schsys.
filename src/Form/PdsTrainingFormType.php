<?php

namespace App\Form;

use App\Entity\PdsTraining;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** One row of PDS item 29. */
class PdsTrainingFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => 'Title of training / L&D intervention (write in full)'])
            ->add('sponsor', TextType::class, ['label' => 'Conducted / sponsored by (write in full)', 'required' => false])
            ->add('dateFrom', DateType::class, ['label' => 'From', 'widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('dateTo', DateType::class, ['label' => 'To', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false])
            ->add('hours', TextType::class, ['label' => 'Number of hours', 'required' => false, 'attr' => ['inputmode' => 'decimal', 'placeholder' => 'e.g. 24']])
            ->add('type', TextType::class, [
                'label' => 'Type of L&D',
                'required' => false,
                'attr' => ['list' => 'training-types', 'placeholder' => 'e.g. Technical'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PdsTraining::class]);
    }
}
