<?php

declare(strict_types=1);

namespace App\Learners\Presentation\Form;

use App\Learners\Application\UseCase\Command\RequestTransferCommand;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * The transfer request form: a section picker from the given sections of the receiving school.
 *
 * @extends AbstractType<RequestTransferCommand>
 */
final class TransferRequestFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('section', ChoiceType::class, [
            'label' => 'Section',
            'property_path' => 'sectionId',
            'choices' => $options['sections'],
            'placeholder' => 'Choose a section',
            'constraints' => [new NotNull(message: 'Choose a section.')],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RequestTransferCommand::class,
            'sections' => [],
        ]);
        $resolver->setAllowedTypes('sections', 'array');
    }

    /** The field names the enroll page's transfer request form has always posted. */
    public function getBlockPrefix(): string
    {
        return 'enroll_section_form';
    }
}
