<?php

namespace App\Form;

use App\Entity\PdsEligibility;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** One row of PDS item 27. */
class PdsEligibilityFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $date = static fn (string $label): array => [
            'label' => $label,
            'widget' => 'single_text',
            'input' => 'datetime_immutable',
            'required' => false,
        ];

        $builder
            ->add('name', TextType::class, [
                'label' => 'Career service / RA 1080 (board/bar) / CES / CSEE / barangay eligibility / driver\'s license',
                'attr' => ['list' => 'eligibility-names', 'placeholder' => 'e.g. Professional Teacher (RA 1080 / LET)'],
            ])
            ->add('rating', TextType::class, ['label' => 'Rating (if applicable)', 'required' => false, 'attr' => ['placeholder' => 'e.g. 82.40']])
            ->add('examDate', DateType::class, $date('Date of examination / conferment'))
            ->add('examPlace', TextType::class, ['label' => 'Place of examination / conferment', 'required' => false])
            ->add('licenseNo', TextType::class, ['label' => 'License number (if applicable)', 'required' => false])
            ->add('licenseValidUntil', DateType::class, $date('License valid until'));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PdsEligibility::class]);
    }
}
