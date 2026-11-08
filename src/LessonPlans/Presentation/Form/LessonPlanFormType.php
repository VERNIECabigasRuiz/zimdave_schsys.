<?php

declare(strict_types=1);

namespace App\LessonPlans\Presentation\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * The whole plan as the teacher edits it; every day cell is a textarea, one bullet per line.
 *
 * @extends AbstractType<LessonPlanContent>
 */
class LessonPlanFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('topic', TextType::class, [
                'label' => 'Topic (name of lesson)',
                'empty_data' => '',
                'constraints' => [new NotBlank(message: 'Type the topic.'), new Length(max: 200)],
            ])
            ->add('competencies', TextareaType::class, [
                'label' => 'Learning competencies',
                'empty_data' => '',
                'help' => 'One per line, with the code if you have it.',
                'attr' => ['rows' => 3],
                'constraints' => [new NotBlank(message: 'Type at least one learning competency.')],
            ])
            ->add('classNotes', TextareaType::class, [
                'label' => 'Notes about this class (optional)',
                'required' => false,
                'help' => 'e.g. many slow readers, no projector.',
                'attr' => ['rows' => 2],
            ])
            ->add('days', CollectionType::class, [
                'entry_type' => LessonPlanDayFormType::class,
                'label' => false,
            ])
            ->add('references', TextareaType::class, ['required' => false, 'attr' => ['rows' => 3]])
            ->add(
                'aiDeclaration',
                TextareaType::class,
                ['label' => 'Declaration of AI use', 'required' => false, 'attr' => ['rows' => 2]],
            )
            ->add('save', SubmitType::class, ['label' => 'Save plan']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => LessonPlanContent::class]);
    }
}
