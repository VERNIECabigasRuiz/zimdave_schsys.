<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Hands a month's SF2 to the principal; school days without attendance must be confirmed as all present. */
class Sf2SubmitFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['missing_days'] > 0) {
            $builder->add('confirmMissing', CheckboxType::class, [
                'label' => \sprintf('%d school %s no attendance. Everyone counts as present on %s.', $options['missing_days'], 1 === $options['missing_days'] ? 'day has' : 'days have', 1 === $options['missing_days'] ? 'that day' : 'those days'),
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null, 'missing_days' => 0]);
        $resolver->setAllowedTypes('missing_days', 'int');
    }
}
