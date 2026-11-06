<?php

declare(strict_types=1);

namespace App\Billing\Presentation\Form;

use App\Billing\Application\UseCase\Command\CreatePlanCommand;
use App\Billing\Application\UseCase\Command\UpdatePlanCommand;
use App\Billing\Presentation\Validation\UniquePlanName;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Creates a plan (bound to CreatePlanCommand) or edits one (bound to UpdatePlanCommand).
 *
 * @extends AbstractType<CreatePlanCommand|UpdatePlanCommand>
 */
class PlanFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Plan name',
                'empty_data' => '',
                'attr' => ['placeholder' => 'e.g. Standard'],
            ])
            ->add('teachersPerSeat', IntegerType::class, [
                'label' => 'Teachers per seat',
                'attr' => ['min' => 1],
            ])
            ->add('studentsPerSeat', IntegerType::class, [
                'label' => 'Students per seat',
                'attr' => ['min' => 1],
            ])
            ->add('includesPrincipal', CheckboxType::class, [
                'label' => 'Includes a principal',
                'required' => false,
                'help' => 'Turn off for teacher-only plans such as Individual.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['constraints' => [new UniquePlanName()]]);
    }
}
