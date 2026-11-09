<?php

declare(strict_types=1);

namespace App\Staff\Presentation\Form;

use App\Staff\Application\UseCase\Command\SaveStaffIdDetailsCommand;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The parts of a staff ID the PDS doesn't hold.
 *
 * @extends AbstractType<SaveStaffIdDetailsCommand>
 */
class StaffIdDetailsFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('callName', TextType::class, [
                'label' => 'Name in big letters (Big ID)',
                'required' => false,
                'help' => 'e.g. Sir Jun. Leave blank for Sir/Ma\'am and your first name.',
            ])
            ->add('emergencyName', TextType::class, [
                'label' => 'In case of emergency, contact',
                'required' => false,
                'attr' => ['placeholder' => 'e.g. Maria Dela Cruz'],
            ])
            ->add('emergencyContact', TextType::class, [
                'label' => 'Contact no(s).',
                'required' => false,
                'attr' => ['placeholder' => 'e.g. 0917 123 4567'],
            ])
            ->add('gsisPolicyNo', TextType::class, [
                'label' => 'GSIS Policy No.',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SaveStaffIdDetailsCommand::class]);
    }
}
