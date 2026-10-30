<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** The principal locks a grading period for the whole school; the box makes them say so. */
class GradeLockFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('confirm', CheckboxType::class, [
            'label' => \sprintf('Yes, lock every class in %s', $options['period_label']),
            'required' => true,
            'constraints' => [new Assert\IsTrue(message: \sprintf('Tick "Yes, lock every class in %s" to lock it.', $options['period_label']))],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
        $resolver->setRequired('period_label');
        $resolver->setAllowedTypes('period_label', 'string');
    }
}
