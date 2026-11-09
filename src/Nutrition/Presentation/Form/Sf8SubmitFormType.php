<?php

declare(strict_types=1);

namespace App\Nutrition\Presentation\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Hands a period's SF8 to the principal; learners without weight and height must be confirmed as unweighed.
 *
 * @extends AbstractType<mixed>
 */
class Sf8SubmitFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $unweighed = $options['unweighed'];
        \assert(\is_int($unweighed));
        if ($unweighed > 0) {
            $builder->add('confirm_unweighed', CheckboxType::class, [
                'label' => \sprintf(
                    '%d %s no weight and height. Submit anyway; %s left out of the counts.',
                    $unweighed,
                    1 === $unweighed ? 'learner has' : 'learners have',
                    1 === $unweighed ? 'that learner is' : 'they are',
                ),
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
