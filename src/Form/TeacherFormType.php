<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TeacherFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('fullName', TextType::class, [
                'label' => 'Full name',
                'empty_data' => '',
                'attr' => ['placeholder' => 'e.g. Ana M. Reyes'],
            ])
            ->add('username', TextType::class, [
                'empty_data' => '',
                'help' => 'Used to sign in. Letters, numbers, dots, dashes and underscores.',
                'attr' => ['placeholder' => 'e.g. ana.reyes'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
