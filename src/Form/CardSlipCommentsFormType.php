<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A class's card slip comments for a term: an entry per editable learner, keyed by enrollment id. A slip prints
 * at most 3 built-in comments. A form posted for another class list is refused whole.
 */
class CardSlipCommentsFormType extends AbstractType
{
    public const MAX_CODES = 3;
    public const TOO_MANY = 'Choose at most 3 comments for %s.';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $names = $options['names'];
        $builder
            ->add('entries', CollectionType::class, ['entry_type' => CardSlipCommentEntryFormType::class, 'label' => false])
            ->addEventListener(FormEvents::PRE_SUBMIT, static fn (FormEvent $event) => GradeSheetFormType::matchRosterOf($event, 'entries'))
            ->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event) use ($names): void {
                foreach ($event->getForm()->get('entries') as $id => $entry) {
                    $codes = $entry->get('codes');
                    if (\count((array) $codes->getData()) > self::MAX_CODES) {
                        $codes->addError(new FormError(\sprintf(self::TOO_MANY, $names[$id] ?? 'this learner')));
                    }
                }
            });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CardSlipCommentsData::class, 'names' => []]);
        // learner names by enrollment id, for the messages
        $resolver->setAllowedTypes('names', 'array');
    }
}
