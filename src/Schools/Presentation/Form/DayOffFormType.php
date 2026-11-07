<?php

declare(strict_types=1);

namespace App\Schools\Presentation\Form;

use App\Schools\Application\UseCase\Command\AddDaysOffCommand;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One day off, or a range of them (every weekday from "from" to "to").
 *
 * @extends AbstractType<AddDaysOffCommand>
 */
class DayOffFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('from', DateType::class, [
                'label' => 'Date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
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
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AddDaysOffCommand::class]);
    }
}
