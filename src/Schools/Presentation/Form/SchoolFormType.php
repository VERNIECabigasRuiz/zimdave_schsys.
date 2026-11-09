<?php

declare(strict_types=1);

namespace App\Schools\Presentation\Form;

use App\Schools\Application\UseCase\Command\SchoolDetails;
use App\Schools\Domain\Model\PhilippineDivisions;
use App\Schools\Domain\Model\SchoolType;
use App\Schools\Presentation\Validation\UniqueSchoolId;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Creates or edits a school (bound to CreateSchoolCommand or UpdateSchoolCommand).
 *
 * @extends AbstractType<SchoolDetails>
 */
class SchoolFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<array{id: int, name: string, teachersPerSeat: ?int, studentsPerSeat: ?int}> $plans */
        $plans = $options['plans'];
        $planChoices = [];
        foreach ($plans as $plan) {
            $label = \sprintf(
                '%s — %d teacher(s), %s students per seat',
                $plan['name'],
                $plan['teachersPerSeat'],
                number_format((int) $plan['studentsPerSeat']),
            );
            $planChoices[$label] = $plan['id'];
        }

        $builder
            ->add('schoolId', TextType::class, [
                'label' => 'DepEd School ID',
                'empty_data' => '',
                'attr' => ['placeholder' => 'e.g. 303456', 'inputmode' => 'numeric', 'maxlength' => 6],
            ])
            ->add('name', TextType::class, [
                'label' => 'School name',
                'empty_data' => '',
            ])
            ->add('type', EnumType::class, [
                'class' => SchoolType::class,
                'label' => 'School type',
                'placeholder' => 'Select type',
                'choice_label' => static fn (SchoolType $type) => $type->label(),
            ])
            ->add('district', TextType::class, [
                'required' => false,
            ])
            ->add('address', TextType::class, [
                'required' => false,
            ])
            ->add('plan', ChoiceType::class, [
                'property_path' => 'planId',
                'choices' => $planChoices,
                'placeholder' => 'Choose a plan',
            ])
            ->add('seats', IntegerType::class, [
                'help' => 'Limits are the plan numbers times the seats, e.g. 2 Individual seats = 2 teachers.',
                'attr' => ['min' => 1],
            ])
            ->add('planValidUntil', DateType::class, [
                'label' => 'Plan valid until',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'help' => 'Last paid day. Leave empty if the plan never expires.',
            ]);

        // Region and division are added once the school is known, so a value typed
        // before these became dropdowns stays selectable instead of being wiped.
        $original = ['', ''];
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event) use (&$original): void {
            $school = $event->getData();
            \assert($school instanceof SchoolDetails);
            $original = [(string) $school->region, (string) $school->division];

            $regions = [];
            foreach (PhilippineDivisions::regions() as $region) {
                $regions[PhilippineDivisions::label($region)] = $region;
            }
            $divisions = PhilippineDivisions::allDivisions();

            $event->getForm()
                ->add('region', ChoiceType::class, [
                    'choices' => self::withOldValue($regions, $original[0]),
                    'placeholder' => 'Select region',
                    'empty_data' => '',
                    'invalid_message' => 'Choose a region from the list.',
                    'attr' => ['data-region-division-target' => 'region', 'data-action' => 'region-division#change'],
                ])
                ->add('division', ChoiceType::class, [
                    'choices' => self::withOldValue(array_combine($divisions, $divisions), $original[1]),
                    'placeholder' => 'Select division',
                    'empty_data' => '',
                    'invalid_message' => 'Choose a division from the list.',
                    'attr' => ['data-region-division-target' => 'division'],
                ]);
        });

        // A division name can exist in two regions, so the pair is what must match.
        // An old typed pair left as it was is allowed through.
        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event) use (&$original): void {
            $form = $event->getForm();
            $region = self::text($form->get('region')->getData());
            $division = self::text($form->get('division')->getData());

            if ('' === $region || '' === $division || [$region, $division] === $original) {
                return;
            }
            if (!PhilippineDivisions::belongsTo($division, $region)) {
                $form->get('division')->addError(new FormError('Choose a division in the selected region.'));
            }
        });
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        // Lets the browser narrow the division list to the chosen region
        $map = [];
        foreach (PhilippineDivisions::regions() as $region) {
            $map[$region] = PhilippineDivisions::divisionsOf($region);
        }
        $view->vars['division_map'] = $map;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'constraints' => [new UniqueSchoolId()],
            'plans' => [],
        ]);
        $resolver->setAllowedTypes('plans', 'array');
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    /**
     * @param array<string, string> $choices label => value
     *
     * @return array<string, string>
     */
    private static function withOldValue(array $choices, string $value): array
    {
        if ('' === $value || \in_array($value, $choices, true)) {
            return $choices;
        }

        return [$value . ' (not on the list)' => $value] + $choices;
    }
}
