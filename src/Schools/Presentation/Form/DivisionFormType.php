<?php

declare(strict_types=1);

namespace App\Schools\Presentation\Form;

use App\Core\Presentation\Form\ImageUploadFormType;
use App\Schools\Application\UseCase\Command\DivisionDetails;
use App\Schools\Domain\Model\Division;
use App\Schools\Domain\Model\PhilippineDivisions;
use App\Schools\Presentation\Validation\UniqueDivision;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A Divisions list entry: the same region and division dropdowns as the school form, and the Superintendent.
 *
 * @extends AbstractType<DivisionDetails>
 */
class DivisionFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $regions = [];
        foreach (PhilippineDivisions::regions() as $region) {
            $regions[PhilippineDivisions::label($region)] = $region;
        }
        $divisions = PhilippineDivisions::allDivisions();

        $builder
            ->add('region', ChoiceType::class, [
                'choices' => $regions,
                'placeholder' => 'Select region',
                'empty_data' => '',
                'invalid_message' => 'Choose a region from the list.',
                'attr' => ['data-region-division-target' => 'region', 'data-action' => 'region-division#change'],
            ])
            ->add('name', ChoiceType::class, [
                'label' => 'Division',
                'choices' => array_combine($divisions, $divisions),
                'placeholder' => 'Select division',
                'empty_data' => '',
                'invalid_message' => 'Choose a division from the list.',
                'attr' => ['data-region-division-target' => 'division'],
            ])
            ->add('sdsName', TextType::class, [
                'label' => 'Schools Division Superintendent',
                'attr' => ['placeholder' => 'e.g. DR. MARIA A. SANTOS, CESO V'],
            ])
            ->add('sdsPosition', TextType::class, [
                'label' => 'Position',
                'required' => false,
                'empty_data' => '',
                'help' => 'Leave blank for "' . Division::SDS . '".',
            ])
            ->add('signature', FileType::class, [
                'label' => 'Signature',
                'mapped' => false,
                'required' => false,
                'help' => 'PNG with a transparent background works best. Printed on principals\' IDs.',
                'attr' => ['accept' => 'image/jpeg,image/png,image/webp'],
                'constraints' => [ImageUploadFormType::imageConstraint()],
            ]);

        // A division name can exist in two regions, so the pair is what must match
        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $form = $event->getForm();
            $region = self::text($form->get('region')->getData());
            $division = self::text($form->get('name')->getData());

            if ('' !== $region && '' !== $division && !PhilippineDivisions::belongsTo($division, $region)) {
                $form->get('name')->addError(new FormError('Choose a division in the selected region.'));
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
        $resolver->setDefaults(['constraints' => [new UniqueDivision()]]);
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
