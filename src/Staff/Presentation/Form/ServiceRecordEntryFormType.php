<?php

declare(strict_types=1);

namespace App\Staff\Presentation\Form;

use App\Staff\Application\UseCase\Command\SaveServiceRowCommand;
use App\Staff\Domain\Model\ServiceRecordEntry;
use App\Staff\Presentation\Validation\ValidServiceRow;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One row of a service record. "Close the current row" only shows when adding while a row runs to the present.
 *
 * @extends AbstractType<SaveServiceRowCommand>
 */
class ServiceRecordEntryFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $date = static fn (string $label, bool $required = false) => [
            'label' => $label,
            'widget' => 'single_text',
            'input' => 'datetime_immutable',
            'required' => $required,
        ];

        $builder
            ->add('fromDate', DateType::class, $date('From', true))
            ->add('toDate', DateType::class, $date('To') + ['help' => 'Leave empty for "Present".'])
            ->add('designation', TextType::class, [
                'label' => 'Designation',
                'attr' => ['placeholder' => 'e.g. Teacher III'],
            ])
            ->add('status', TextType::class, ['label' => 'Status', 'attr' => ['list' => 'sr-statuses']])
            ->add('annualSalary', NumberType::class, [
                'label' => 'Annual salary',
                'input' => 'string',
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.01', 'min' => 0],
            ])
            ->add('station', TextType::class, ['label' => 'Station / place of assignment'])
            ->add('branch', ChoiceType::class, [
                'label' => 'Branch',
                'choices' => array_combine(ServiceRecordEntry::BRANCHES, ServiceRecordEntry::BRANCHES),
            ])
            ->add('lwopFrom', DateType::class, $date('Leave without pay from'))
            ->add('lwopTo', DateType::class, $date('Leave without pay to'))
            ->add('separationDate', DateType::class, $date('Separation date'))
            ->add('separationCause', TextType::class, ['label' => 'Separation cause', 'required' => false])
            ->add('remarks', TextType::class, [
                'label' => 'Remarks',
                'required' => false,
                'attr' => ['placeholder' => 'e.g. SG13-4 NBC-601'],
            ]);

        if ($options['can_close_open']) {
            $builder->add('closeOpen', CheckboxType::class, [
                'label' => 'Close the current row on the day before',
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SaveServiceRowCommand::class,
            'can_close_open' => false,
            'constraints' => [new ValidServiceRow()],
        ]);
        $resolver->setAllowedTypes('can_close_open', 'bool');
    }
}
