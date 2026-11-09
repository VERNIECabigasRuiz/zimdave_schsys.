<?php

declare(strict_types=1);

namespace App\Grading\Presentation\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The principal locks a grading period for the whole school; the box makes them say so.
 *
 * @extends AbstractType<mixed>
 */
class GradeLockFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $label = $options['period_label'];
        \assert(\is_string($label));
        $builder->add('confirm', CheckboxType::class, [
            'label' => \sprintf('Yes, lock every class in %s', $label),
            'required' => true,
            'constraints' => [
                new Assert\IsTrue(
                    message: \sprintf('Tick "Yes, lock every class in %s" to lock it.', $label),
                ),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
        $resolver->setRequired('period_label');
        $resolver->setAllowedTypes('period_label', 'string');
    }
}
