<?php

namespace App\Form;

use App\Entity\PersonalDataSheet;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Valid;

/**
 * One PDS list tab (III–VII): a single collection of rows on the sheet.
 * The controller names each instance (e.g. "education_section_form").
 */
class PdsListFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add($options['field'], CollectionType::class, [
            'label' => false,
            'entry_type' => $options['entry_type'],
            'entry_options' => ['label' => false],
            'allow_add' => true,
            'allow_delete' => true,
            'by_reference' => false,
            'prototype' => true,
            'error_bubbling' => false,
            // Only this tab's list is checked, so a bad row elsewhere never blocks this save
            'constraints' => [
                new Valid(groups: PersonalDataSheet::LIST_GROUPS),
                new Count(max: PersonalDataSheet::MAX_ROWS, maxMessage: 'List at most {{ limit }} entries.', groups: PersonalDataSheet::LIST_GROUPS),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PersonalDataSheet::class,
            'validation_groups' => [PersonalDataSheet::LISTS],
        ]);
        $resolver->setRequired(['field', 'entry_type']);
        $resolver->setAllowedTypes('field', 'string');
        $resolver->setAllowedTypes('entry_type', 'string');
    }
}
