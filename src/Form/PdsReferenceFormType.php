<?php

namespace App\Form;

use App\Entity\PdsReference;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** One row of PDS item 41. */
class PdsReferenceFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Name', 'required' => false])
            ->add('address', TextType::class, ['label' => 'Office / residential address', 'required' => false])
            ->add('contact', TextType::class, ['label' => 'Contact no. and/or e-mail', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PdsReference::class]);
    }
}
