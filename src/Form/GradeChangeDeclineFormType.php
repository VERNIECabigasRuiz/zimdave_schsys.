<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** The principal declines a grade change request with a note for the teacher. One per pending request, named per id. */
class GradeChangeDeclineFormType extends AbstractType
{
    public const NOTE = 'Write a note for the teacher (at least 5 characters).';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('note', TextareaType::class, [
            'label' => 'Note for the teacher',
            'empty_data' => '',
            'trim' => true,
            'attr' => ['rows' => 2, 'maxlength' => 1000],
            'constraints' => [
                new Assert\NotBlank(message: self::NOTE),
                new Assert\Length(min: 5, max: 1000, minMessage: self::NOTE, maxMessage: self::NOTE),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
