<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A change request for a locked grade sheet: a corrected-grade box per editable learner, keyed by enrollment id, and
 * the reason. Like the sheet, a form posted for another class list is refused whole.
 */
class GradeChangeFormType extends AbstractType
{
    public const REASON = 'Write the reason for the change (at least 10 characters).';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('grades', CollectionType::class, [
                'entry_type' => IntegerType::class,
                'entry_options' => [
                    'label' => false,
                    'required' => false,
                    'invalid_message' => GradeSheetFormType::OUT_OF_RANGE,
                    'constraints' => [new Assert\Range(notInRangeMessage: GradeSheetFormType::OUT_OF_RANGE, min: 60, max: 100)],
                ],
                'label' => false,
            ])
            ->add('reason', TextareaType::class, [
                'label' => 'Reason for the change',
                // a blank reason arrives as '' (trimmed), which Length alone reports, so the message shows once
                'empty_data' => '',
                'attr' => ['rows' => 4, 'maxlength' => 1000],
                'constraints' => [new Assert\Length(min: 10, max: 1000, minMessage: self::REASON, maxMessage: self::REASON)],
            ])
            ->addEventListener(FormEvents::PRE_SUBMIT, GradeSheetFormType::matchRoster(...));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => GradeChange::class]);
    }
}
