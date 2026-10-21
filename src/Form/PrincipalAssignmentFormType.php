<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;

class PrincipalAssignmentFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('principal', EntityType::class, [
            'class' => User::class,
            'choices' => $options['principals'],
            'choice_label' => 'fullName',
            'placeholder' => 'Select a principal',
            'label' => 'Principal',
            'constraints' => [new NotNull(message: 'Select a principal.')],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('principals')
            ->setAllowedTypes('principals', 'array');
    }
}
