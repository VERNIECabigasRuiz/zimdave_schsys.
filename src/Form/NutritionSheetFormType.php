<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A period's weighing sheet: the date of weighing and a line per learner on the roster, keyed by enrollment id.
 * A sheet posted for another class list (a learner left or joined after the page was opened) is refused whole.
 */
class NutritionSheetFormType extends AbstractType
{
    public const ROSTER_CHANGED = 'The class list changed while you were editing. Check the rows and save again.';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('weighedOn', DateType::class, ['label' => 'Date of weighing', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => true])
            ->add('entries', CollectionType::class, ['entry_type' => NutritionEntryFormType::class, 'label' => false])
            ->addEventListener(FormEvents::PRE_SUBMIT, self::matchRoster(...));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => NutritionSheet::class]);
    }

    /** Drops lines for learners no longer on the roster and keeps the saved values of learners who joined. */
    private static function matchRoster(FormEvent $event): void
    {
        $data = $event->getData();
        if (!\is_array($data)) {
            return;
        }
        $posted = \is_array($data['entries'] ?? null) ? $data['entries'] : [];
        $entries = [];
        $changed = false;
        foreach ($event->getForm()->get('entries') as $key => $entry) {
            if (\array_key_exists($key, $posted)) {
                $entries[$key] = $posted[$key];
                continue;
            }
            $changed = true;
            foreach ($entry as $name => $field) {
                $entries[$key][$name] = $field->getViewData();
            }
        }
        if ($changed || \count($posted) !== \count($entries)) {
            $event->getForm()->addError(new FormError(self::ROSTER_CHANGED));
        }
        $data['entries'] = $entries;
        $event->setData($data);
    }
}
