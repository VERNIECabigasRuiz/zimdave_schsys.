<?php

namespace App\Form;

use App\Entity\PersonalDataSheet;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** PDS items 34–40, worded exactly as CS Form 212 (Revised 2026), page 4. */
class QuestionsFormType extends AbstractType
{
    /** Question wording by PersonalDataSheet::QUESTION_DETAILS key. */
    public const WORDING = [
        '34' => '34. Are you related by consanguinity or affinity to the appointing or recommending authority, or to the chief of bureau or office or to the person who has immediate supervision over you in the Office, Bureau or Department where you will be appointed,',
        '35a' => '35. a. Have you ever been found guilty of any administrative offense?',
        '35b' => '35. b. Have you been criminally charged before any court?',
        '36' => '36. Have you ever been convicted of any crime or violation of any law, decree, ordinance or regulation by any court or tribunal?',
        '37' => '37. Have you ever been separated from the service in any of the following modes: resignation, retirement, dropped from the rolls, dismissal, termination, end of term, finished contract or phased out (abolition) in the public or private sector?',
        '38a' => '38. a. Have you ever been a candidate in a national or local election held within the last year (except Barangay election)?',
        '38b' => '38. b. Have you resigned from the government service during the three (3)-month period before the last election to promote/actively campaign for a national or local candidate?',
        '39' => '39. Have you acquired the status of an immigrant or permanent resident of another country?',
        '40a' => '40. a. Are you a member of any indigenous group?',
        '40b' => '40. b. Are you a person with disability?',
        '40c' => '40. c. Are you a solo parent?',
    ];

    /** Item 40 preamble, shown before 40a. */
    public const ITEM_40 = 'Pursuant to: (a) Indigenous People\'s Act (RA 8371); (b) Magna Carta for Disabled Persons (RA 7277, as amended); and (c) Expanded Solo Parents Welfare Act (RA 11861), please answer the following items:';

    private const ANSWER_LABELS = ['q34a' => 'a. within the third degree?', 'q34b' => 'b. within the fourth degree (for Local Government Unit - Career Employees)?'];

    private const DETAIL_LABELS = [
        'q35bDateFiled' => 'Date filed',
        'q35bStatus' => 'Status of case/s',
        'q39Details' => 'If YES, give details (country)',
        'q40aDetails' => 'If YES, please specify',
        'q40bDetails' => 'If YES, please specify ID no.',
        'q40cDetails' => 'If YES, please specify ID no.',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (PersonalDataSheet::QUESTION_DETAILS as [$answers, $details]) {
            foreach ($answers as $answer) {
                $builder->add($answer, ChoiceType::class, [
                    'label' => self::ANSWER_LABELS[$answer] ?? false,
                    'choices' => ['Yes' => true, 'No' => false],
                    'choice_value' => static fn (?bool $yes) => null === $yes ? '' : ($yes ? '1' : '0'),
                    'expanded' => true,
                    'required' => false,
                    'placeholder' => false,
                    'attr' => ['class' => 'choice-chips'],
                ]);
            }
            foreach ($details as $field) {
                $builder->add($field, 'q35bDateFiled' === $field ? DateType::class : TextType::class, [
                    'label' => self::DETAIL_LABELS[$field] ?? 'If YES, give details',
                    'required' => false,
                ] + ('q35bDateFiled' === $field ? ['widget' => 'single_text', 'input' => 'datetime_immutable'] : []));
            }
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PersonalDataSheet::class,
            'validation_groups' => [PersonalDataSheet::QUESTIONS],
        ]);
    }
}
