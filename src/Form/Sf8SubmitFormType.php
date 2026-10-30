<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Hands a period's SF8 to the principal; learners without weight and height must be confirmed as unweighed. */
class Sf8SubmitFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['unweighed'] > 0) {
            $builder->add('confirm_unweighed', CheckboxType::class, [
                'label' => \sprintf('%d %s no weight and height. Submit anyway; %s left out of the counts.', $options['unweighed'], 1 === $options['unweighed'] ? 'learner has' : 'learners have', 1 === $options['unweighed'] ? 'that learner is' : 'they are'),
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null, 'unweighed' => 0]);
        $resolver->setAllowedTypes('unweighed', 'int');
    }
}
