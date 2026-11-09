<?php

declare(strict_types=1);

namespace App\Schools\Presentation\Form;

use App\Schools\Application\UseCase\Command\AssignPrincipalCommand;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Picks the principal of a school (bound to AssignPrincipalCommand).
 *
 * @extends AbstractType<AssignPrincipalCommand>
 */
class PrincipalAssignmentFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<array{id: int, fullName: string}> $principals */
        $principals = $options['principals'];
        $names = array_column($principals, 'fullName', 'id');

        $builder->add('principal', ChoiceType::class, [
            'property_path' => 'principalId',
            'choices' => array_keys($names),
            'choice_label' => static fn (int $id): string => $names[$id],
            'placeholder' => 'Select a principal',
            'label' => 'Principal',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('principals')
            ->setAllowedTypes('principals', 'array');
    }
}
