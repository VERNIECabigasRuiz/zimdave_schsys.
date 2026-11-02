<?php

namespace App\Form;

use App\Attendance\RosterEntry;
use App\Entity\LearnerMark;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** A day's roll call: one mark per learner who counts that day; left empty, the learner is present. */
class RollCallFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach ($options['entries'] as $entry) {
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
        $resolver->setAllowedTypes('entries', RosterEntry::class.'[]');
    }

    public static function field(RosterEntry $entry): string
    {
        return 'mark_'.$entry->enrollment->getId();
    }
}
