<?php

namespace App\Form;

use App\Entity\School;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * What a principal may change about their school. School ID, name, region,
 * division and district are not fields here, so they can't be changed.
 */
class SchoolProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('logo', FileType::class, [
                'label' => 'School logo',
                'mapped' => false,
                'required' => false,
                'help' => 'JPG, PNG or WebP up to 5 MB. It is resized to fit 400 × 400.',
                'attr' => ['accept' => 'image/jpeg,image/png,image/webp', 'data-action' => 'image-preview#show'],
                'constraints' => [ImageUploadFormType::imageConstraint()],
            ])
            ->add('address', TextType::class, [
                'label' => 'School address',
                'required' => false,
                'attr' => ['placeholder' => 'e.g. Pagsabungan, Mandaue City, Cebu'],
            ])
            ->add('contactNumber', TelType::class, [
                'label' => 'Contact number',
                'required' => false,
                'attr' => ['placeholder' => 'e.g. (032) 123-4567'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'School email',
                'required' => false,
                'attr' => ['placeholder' => 'e.g. 303456@deped.gov.ph'],
            ])
            ->add('facebookUrl', UrlType::class, [
                'label' => 'Facebook page',
                'required' => false,
                'default_protocol' => 'https',
                'attr' => ['placeholder' => 'https://facebook.com/yourschool'],
            ])
            ->add('sf8CertifierName', TextType::class, [
                'label' => 'SF8 certified correct by',
                'required' => false,
                'help' => 'Printed under "Certified correct" on the SF8, e.g. the school nurse.',
            ])
            ->add('sf8CertifierPosition', TextType::class, [
                'label' => 'Position',
                'required' => false,
                'attr' => ['placeholder' => 'e.g. School Nurse'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => School::class,
            'validation_groups' => [School::PROFILE],
        ]);
    }
}
