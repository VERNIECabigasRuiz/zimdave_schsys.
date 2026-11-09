<?php

declare(strict_types=1);

namespace App\Staff\Presentation\Form;

use App\Staff\Application\UseCase\Command\SavePdsListCommand;
use App\Staff\Domain\Model\PersonalDataSheet;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Valid;

/**
 * One PDS list tab (III–VII): a single collection of rows on the sheet.
 * The controller names each instance (e.g. "education_section_form").
 *
 * @extends AbstractType<SavePdsListCommand>
 */
class PdsListFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $field = $options['field'];
        \assert(\is_string($field));
        $builder->add($field, CollectionType::class, [
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
                new Count(
                    max: PersonalDataSheet::MAX_ROWS,
                    maxMessage: 'List at most {{ limit }} entries.',
                    groups: PersonalDataSheet::LIST_GROUPS,
                ),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SavePdsListCommand::class,
            'validation_groups' => [PersonalDataSheet::LISTS],
        ]);
        $resolver->setRequired(['field', 'entry_type']);
        $resolver->setAllowedTypes('field', 'string');
        $resolver->setAllowedTypes('entry_type', 'string');
    }
}
