<?php

declare(strict_types=1);

namespace App\Schools\Presentation\Form;

use App\Schools\Application\UseCase\Command\AddExistingTeacherCommand;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The username of a teacher who already has an account (bound to AddExistingTeacherCommand).
 *
 * @extends AbstractType<AddExistingTeacherCommand>
 */
class ExistingTeacherFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('username', TextType::class, [
            'empty_data' => '',
            'help' => 'Ask the teacher for their ZIM School username. '
                . 'Only teachers without a school this school year can be added.',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AddExistingTeacherCommand::class]);
    }
}
