<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

class ExistingTeacherFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('username', TextType::class, [
            'empty_data' => '',
            'help' => "Ask the teacher for their ZIM School username. Only teachers without a school this school year can be added.",
            'constraints' => [new NotBlank(message: 'Enter the teacher\'s username.')],
        ]);
    }
}
