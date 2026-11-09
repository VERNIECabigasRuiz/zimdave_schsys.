<?php

declare(strict_types=1);

namespace App\Attendance\Presentation\Form;

use App\Learners\Application\Api\RosterEntry;
use App\Attendance\Domain\Model\LearnerMark;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A day's roll call: one mark per learner who counts that day; left empty, the learner is present.
 *
 * @extends AbstractType<array<string, ?LearnerMark>>
 */
class RollCallFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<RosterEntry> $entries */
        $entries = $options['entries'];
        foreach ($entries as $entry) {
            $builder->add(self::field($entry), EnumType::class, [
                'label' => $entry->name,
                'class' => LearnerMark::class,
                'required' => false,
                'placeholder' => 'Present',
                'choice_label' => static fn (LearnerMark $mark) => $mark->label(),
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
        $resolver->setRequired('entries');
        $resolver->setAllowedTypes('entries', RosterEntry::class . '[]');
    }

    public static function field(RosterEntry $entry): string
    {
        return 'mark_' . $entry->enrollment->getId();
    }
}
