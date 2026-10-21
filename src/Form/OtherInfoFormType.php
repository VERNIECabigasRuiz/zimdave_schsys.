<?php

namespace App\Form;

use App\Entity\PersonalDataSheet;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/** PDS VIII. Other Information, items 31–33: three lists of one-line entries. */
class OtherInfoFormType extends AbstractType
{
    public const LISTS = [
        'skills' => ['31. Special skills and hobbies', '+ Add skill or hobby', 'e.g. Singing, computer literacy'],
        'distinctions' => ['32. Non-academic distinctions / recognition (write in full)', '+ Add distinction', 'e.g. Outstanding Teacher, Division of Mandaue City 2023'],
        'memberships' => ['33. Membership in association / organization (write in full)', '+ Add membership', 'e.g. National Organization of Professional Teachers'],
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (self::LISTS as $field => [$label, , $placeholder]) {
            $builder->add($field, CollectionType::class, [
                'label' => $label,
                'entry_type' => TextType::class,
                'entry_options' => [
                    'label' => false,
                    'attr' => ['placeholder' => $placeholder],
                    'constraints' => [new NotBlank(message: 'Remove empty lines or fill them in.', groups: PersonalDataSheet::LIST_GROUPS), new Length(max: 255, groups: PersonalDataSheet::LIST_GROUPS)],
                ],
                'allow_add' => true,
                'allow_delete' => true,
                'prototype' => true,
                'error_bubbling' => false,
                'constraints' => [new Count(max: PersonalDataSheet::MAX_ROWS, maxMessage: 'List at most {{ limit }} entries.', groups: PersonalDataSheet::LIST_GROUPS)],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PersonalDataSheet::class,
            'validation_groups' => [PersonalDataSheet::LISTS],
        ]);
    }
}
