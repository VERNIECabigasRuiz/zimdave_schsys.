<?php

declare(strict_types=1);

namespace App\Classes\Presentation\Form;

use App\Classes\Application\UseCase\Command\ClassPeriodDetails;
use App\Classes\Presentation\Validation\NoOverlappingPeriod;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A slot of the school day, bound to a ClassPeriodDetails command.
 *
 * @extends AbstractType<ClassPeriodDetails>
 */
class ClassPeriodFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('startsAt', TimeType::class, [
                'label' => 'Starts at',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => ['step' => 300],
            ])
            ->add('endsAt', TimeType::class, [
                'label' => 'Ends at',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => ['step' => 300],
            ])
            ->add('isBreak', CheckboxType::class, [
                'label' => 'This is a break, not a class period',
                'required' => false,
            ])
            ->add('breakLabel', TextType::class, [
                'label' => 'Break name',
                'required' => false,
                'attr' => ['placeholder' => 'e.g. Recess, Lunch', 'maxlength' => 40],
                'help' => 'Only for a break. Breaks are not numbered and cannot hold classes.',
            ])
            ->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
                $form = $event->getForm();
                $period = $event->getData();
                \assert($period instanceof ClassPeriodDetails);
                if (!$period->isBreak) {
                    $period->breakLabel = null;
                } elseif (null === $period->breakLabel || '' === trim($period->breakLabel)) {
                    $form->get('breakLabel')->addError(new FormError('Enter the break name.'));
                }
            });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'constraints' => [new NoOverlappingPeriod()],
        ]);
    }
}
