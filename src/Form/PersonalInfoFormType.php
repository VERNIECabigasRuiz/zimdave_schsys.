<?php

namespace App\Form;

use App\Entity\Citizenship;
use App\Entity\CivilStatus;
use App\Entity\DualCitizenshipBy;
use App\Entity\PersonalDataSheet;
use App\Entity\SexAtBirth;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** PDS I. Personal Information, items 1–21. Labels carry the form's item numbers. */
class PersonalInfoFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('surname', TextType::class, ['label' => '1. Surname'])
            ->add('firstName', TextType::class, ['label' => '2. First name'])
            ->add('middleName', TextType::class, ['label' => 'Middle name', 'required' => false])
            ->add('nameExtension', TextType::class, [
                'label' => 'Name extension',
                'required' => false,
                'attr' => ['placeholder' => 'Jr., Sr., III'],
            ])
            ->add('dateOfBirth', DateType::class, [
                'label' => '3. Date of birth',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('placeOfBirth', TextType::class, ['label' => '4. Place of birth', 'required' => false])
            ->add('sexAtBirth', EnumType::class, [
                'label' => '5. Sex at birth',
                'class' => SexAtBirth::class,
                'expanded' => true,
                'choice_label' => static fn (SexAtBirth $sex) => $sex->label(),
            ])
            ->add('civilStatus', EnumType::class, [
                'label' => '6. Civil status',
                'class' => CivilStatus::class,
                'expanded' => true,
                'choice_label' => static fn (CivilStatus $status) => $status->label(),
            ])
            ->add('civilStatusOther', TextType::class, [
                'label' => 'If Other/s, specify',
                'required' => false,
            ])
            ->add('height', TextType::class, [
                'label' => '7. Height (m)',
                'required' => false,
                'attr' => ['inputmode' => 'decimal', 'placeholder' => 'e.g. 1.60'],
            ])
            ->add('weight', TextType::class, [
                'label' => '8. Weight (kg)',
                'required' => false,
                'attr' => ['inputmode' => 'decimal', 'placeholder' => 'e.g. 55'],
            ])
            ->add('bloodType', ChoiceType::class, [
                'label' => '9. Blood type',
                'required' => false,
                'placeholder' => 'Select',
                'choices' => array_combine(PersonalDataSheet::BLOOD_TYPES, PersonalDataSheet::BLOOD_TYPES),
            ])
            ->add('umidNo', TextType::class, ['label' => '10. UMID ID no.', 'required' => false])
            ->add('pagibigNo', TextType::class, ['label' => '11. PAG-IBIG ID no.', 'required' => false])
            ->add('philhealthNo', TextType::class, ['label' => '12. PhilHealth no.', 'required' => false])
            ->add('philsysPcn', TextType::class, ['label' => '13. PhilSys Card Number (PCN)', 'required' => false])
            ->add('tin', TextType::class, ['label' => '14. TIN no.', 'required' => false])
            ->add('agencyEmployeeNo', TextType::class, ['label' => '15. Agency employee no.', 'required' => false])
            ->add('citizenship', EnumType::class, [
                'label' => '16. Citizenship',
                'class' => Citizenship::class,
                'expanded' => true,
                'choice_label' => static fn (Citizenship $c) => $c->label(),
            ])
            ->add('dualCitizenshipBy', EnumType::class, [
                'label' => 'If dual citizenship',
                'class' => DualCitizenshipBy::class,
                'expanded' => true,
                'required' => false,
                'placeholder' => false,
                'choice_label' => static fn (DualCitizenshipBy $by) => $by->label(),
            ])
            ->add('dualCitizenshipCountry', TextType::class, ['label' => 'Country', 'required' => false]);

        $this->addAddress($builder, 'res');
        $builder->add('permSameAsResidential', CheckboxType::class, [
            'label' => 'Same as residential address',
            'required' => false,
        ]);
        $this->addAddress($builder, 'perm');

        $builder
            ->add('telephoneNo', TelType::class, ['label' => '19. Telephone no.', 'required' => false])
            ->add('mobileNo', TelType::class, [
                'label' => '20. Mobile no.',
                'required' => false,
                'attr' => ['placeholder' => '09171234567'],
            ])
            ->add('email', EmailType::class, ['label' => '21. E-mail address', 'required' => false]);
    }

    private function addAddress(FormBuilderInterface $builder, string $prefix): void
    {
        $fields = [
            'HouseNo' => 'House/Block/Lot no.',
            'Street' => 'Street',
            'Subdivision' => 'Subdivision/Village',
            'Barangay' => 'Barangay',
            'City' => 'City/Municipality',
            'Province' => 'Province',
            'Zip' => 'ZIP code',
        ];
        foreach ($fields as $field => $label) {
            $builder->add($prefix.$field, TextType::class, [
                'label' => $label,
                'required' => false,
                'attr' => 'Zip' === $field ? ['inputmode' => 'numeric', 'maxlength' => 4] : [],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PersonalDataSheet::class]);
    }
}
