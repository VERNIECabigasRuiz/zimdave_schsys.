<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** One learner's weight, height and remarks on the weighing sheet. */
class NutritionEntryFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('enrollmentId', HiddenType::class)
            ->add('weightKg', NumberType::class, ['label' => 'Weight (kg)', 'required' => false, 'scale' => 2, 'invalid_message' => 'Enter weight in kilograms, between 5 and 200.'])
            ->add('heightM', NumberType::class, ['label' => 'Height (m)', 'required' => false, 'scale' => 2, 'invalid_message' => 'Enter height in metres, e.g. 1.32.'])
            ->add('remarks', TextType::class, ['label' => 'Remarks', 'required' => false, 'attr' => ['maxlength' => 100]]);

        $builder->get('enrollmentId')->addModelTransformer(new CallbackTransformer(
            static fn (?int $id): string => (string) $id,
            static fn (?string $id): int => null !== $id && ctype_digit($id) ? (int) $id : throw new TransformationFailedException('Not an enrollment id.'),
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => NutritionEntry::class,
            'empty_data' => static fn () => new NutritionEntry(0),
        ]);
    }
}
