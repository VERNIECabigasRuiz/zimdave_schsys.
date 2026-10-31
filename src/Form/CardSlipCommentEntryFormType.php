<?php

namespace App\Form;

use App\CardSlip\CardSlipComments;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** One learner's card slip comments: built-in comments to tick (by code) and a typed comment. */
class CardSlipCommentEntryFormType extends AbstractType
{
    public const TOO_LONG = 'Keep the comment under 300 characters.';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = [];
        foreach (CardSlipComments::GROUPS as $group => $codes) {
            foreach ($codes as $code) {
                $choices[$group][CardSlipComments::text($code)] = $code;
            }
        }

        $builder
            ->add('codes', ChoiceType::class, [
                'choices' => $choices,
                'multiple' => true,
                'expanded' => true,
                // a checkbox named by its code, so the page can lay them out per learner
                'choice_name' => static fn (string $code) => $code,
                'label' => false,
            ])
            ->add('text', TextareaType::class, [
                'label' => 'Typed comment',
                'required' => false,
                'constraints' => [new Assert\Length(max: 300, maxMessage: self::TOO_LONG)],
                'attr' => ['maxlength' => 300, 'rows' => 2],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
