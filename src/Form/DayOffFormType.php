<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** One day off, or a range of them (every weekday from "from" to "to"). */
class DayOffFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('from', DateType::class, [
                'label' => 'Date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new Assert\NotNull(message: 'Pick a date.')],
            ])
            ->add('to', DateType::class, [
                'label' => 'Until (optional)',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => 'For a break: every weekday in between becomes a day off.',
            ])
            ->add('reason', TextType::class, [
                'label' => 'Reason',
                'attr' => ['placeholder' => 'e.g. Bonifacio Day, typhoon suspension, in-service training'],
                'constraints' => [new Assert\NotBlank(message: 'Give a reason.'), new Assert\Length(max: 100)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
