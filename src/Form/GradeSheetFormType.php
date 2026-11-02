<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A grade sheet: a grade box per editable learner, keyed by enrollment id. A sheet posted for another
 * class list (a learner dropped, left or joined after the page was opened) is refused whole.
 */
class GradeSheetFormType extends AbstractType
{
    public const ROSTER_CHANGED = NutritionSheetFormType::ROSTER_CHANGED;
    public const OUT_OF_RANGE = 'Enter a grade from 60 to 100.';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('grades', CollectionType::class, [
                'entry_type' => IntegerType::class,
                'entry_options' => [
                    'label' => false,
                    'required' => false,
                    'invalid_message' => self::OUT_OF_RANGE,
                    'constraints' => [new Assert\Range(notInRangeMessage: self::OUT_OF_RANGE, min: 60, max: 100)],
                ],
                'label' => false,
            ])
            ->addEventListener(FormEvents::PRE_SUBMIT, self::matchRoster(...));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => GradeSheet::class]);
    }

    /** Drops grades for learners no longer editable and keeps the saved grades of learners who joined. */
    public static function matchRoster(FormEvent $event): void
    {
        self::matchRosterOf($event, 'grades');
    }

    /**
     * The same check for any collection keyed by enrollment id (a PRE_SUBMIT listener's body): entries for
     * learners no longer listed are dropped, learners who joined keep what the form holds, and the form gets
     * ROSTER_CHANGED either way.
     */
    public static function matchRosterOf(FormEvent $event, string $collection): void
    {
        $data = $event->getData();
        if (!\is_array($data)) {
            return;
        }
        $posted = \is_array($data[$collection] ?? null) ? $data[$collection] : [];
        $entries = [];
        $changed = false;
        foreach ($event->getForm()->get($collection) as $key => $field) {
            if (\array_key_exists($key, $posted)) {
                $entries[$key] = $posted[$key];
                continue;
            }
            $changed = true;
            if ($field->getConfig()->getCompound()) {
                foreach ($field as $name => $child) {
                    $entries[$key][$name] = $child->getViewData();
                }
            } else {
                $entries[$key] = $field->getViewData();
            }
        }
        if ($changed || \count($posted) !== \count($entries)) {
            $event->getForm()->addError(new FormError(self::ROSTER_CHANGED));
        }
        $data[$collection] = $entries;
        $event->setData($data);
    }
}
