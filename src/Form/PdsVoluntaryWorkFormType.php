<?php

namespace App\Form;

use App\Entity\PdsVoluntaryWork;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** One row of PDS item 30. */
class PdsVoluntaryWorkFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $date = static fn (string $label): array => ['label' => $label, 'widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false];

        $builder
            ->add('organization', TextType::class, ['label' => 'Name & address of organization (write in full)'])
            ->add('nature', TextType::class, ['label' => 'Position / nature of work', 'required' => false])
            ->add('dateFrom', DateType::class, $date('From'))
            ->add('dateTo', DateType::class, $date('To'))
            ->add('hours', TextType::class, ['label' => 'Number of hours', 'required' => false, 'attr' => ['inputmode' => 'decimal']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PdsVoluntaryWork::class]);
    }
}
