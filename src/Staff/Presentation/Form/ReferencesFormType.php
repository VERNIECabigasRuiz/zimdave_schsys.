<?php

declare(strict_types=1);

namespace App\Staff\Presentation\Form;

use App\Staff\Application\UseCase\Command\SaveReferencesCommand;
use App\Staff\Domain\Model\PersonalDataSheet;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * PDS item 41 (three references) and the government-issued ID. The controller always
 * gives the form three reference rows; rows left completely empty are not saved.
 *
 * @extends AbstractType<SaveReferencesCommand>
 */
class ReferencesFormType extends AbstractType
{
    public const GOV_ID_TYPES = [
        'Passport',
        'GSIS',
        'SSS',
        'PRC',
        'Driver\'s License',
        'UMID',
        'PhilSys National ID',
        'Postal ID',
        'Voter\'s ID',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('references', CollectionType::class, [
                'label' => false,
                'entry_type' => PdsReferenceFormType::class,
                'entry_options' => ['label' => false],
                // Always three rows on the page (also after an error); empty ones are removed on save
                'allow_add' => false,
                'allow_delete' => false,
                'by_reference' => false,
                'error_bubbling' => false,
            ])
            ->add('govIdType', TextType::class, [
                'label' => 'Government-issued ID',
                'required' => false,
                'attr' => ['list' => 'gov-id-types', 'placeholder' => 'e.g. PRC'],
            ])
            ->add('govIdNumber', TextType::class, ['label' => 'ID / license / passport no.', 'required' => false])
            ->add('govIdIssued', TextType::class, [
                'label' => 'Date / place of issuance',
                'required' => false,
                'attr' => ['placeholder' => 'e.g. March 3, 2022 / Cebu City'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SaveReferencesCommand::class,
            'validation_groups' => [PersonalDataSheet::REFERENCES],
        ]);
    }
}
