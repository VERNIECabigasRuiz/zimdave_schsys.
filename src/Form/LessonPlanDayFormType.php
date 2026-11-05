<?php

namespace App\Form;

use App\Entity\LessonPlan;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

/**
 * One day's ILAW cells. The day is an array; its date and holiday aren't fields, so they pass through untouched,
 * and a submitted cell left out comes back as an empty string.
 */
class LessonPlanDayFormType extends AbstractType
{
    public const LABELS = [
        'objectives' => 'Learning objectives',
        'learnerContext' => 'Learner context',
        'preLesson' => 'Pre-lesson',
        'flow' => 'Flow',
        'resources' => 'Learning resources',
        'integration' => 'Opportunities for integration',
        'formativeAssessment' => 'Formative assessment',
        'extendedLearning' => 'Extended learning opportunities',
        'reflections' => 'Reflections',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (LessonPlan::DAY_FIELDS as $field) {
            $builder->add($field, TextareaType::class, [
                'label' => self::LABELS[$field],
                'required' => false,
                'empty_data' => '',
                'attr' => ['rows' => 4],
            ]);
        }
        // a holiday has no cells to edit
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
            if (null !== ($event->getData()['holiday'] ?? null)) {
                foreach (LessonPlan::DAY_FIELDS as $field) {
                    $event->getForm()->remove($field);
                }
            }
        });
    }
}
