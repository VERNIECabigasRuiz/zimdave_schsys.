<?php

namespace App\CardSlip;

final class CardSlipComments
{
    public const GROUPS = [
        'Academic' => ['excellent', 'very_good', 'good', 'improved', 'needs_improvement', 'incomplete'],
        'Attendance' => ['perfect_attendance', 'absences', 'tardiness'],
        'Behaviour' => ['participates', 'responsible', 'respectful', 'needs_focus'],
        'Suggestion for parents' => ['study_home', 'read_daily', 'see_adviser'],
    ];

    private const TEXTS = [
        'excellent' => 'Excellent performance this term. Keep up the good work!',
        'very_good' => 'Very good performance this term.',
        'good' => 'Good performance this term. Keep it up.',
        'improved' => 'Showed clear improvement from last term.',
        'needs_improvement' => 'Needs to improve in {subjects}. Please attend remedial sessions.',
        'incomplete' => 'Some grades are not yet complete. Please see the subject teachers.',
        'perfect_attendance' => 'Perfect attendance this term!',
        'absences' => 'Frequent absences affected learning. Please make sure your child attends regularly.',
        'tardiness' => 'Often late to class. Please help your child come to school on time.',
        'participates' => 'Participates actively in class activities.',
        'responsible' => 'Responsible and completes tasks on time.',
        'respectful' => 'Respectful and kind to classmates and teachers.',
        'needs_focus' => 'Needs to focus more during class discussions.',
        'study_home' => 'Please guide your child in reviewing lessons at home.',
        'read_daily' => 'Encourage your child to read every day.',
        'see_adviser' => 'Please see the adviser for a short conference.',
    ];

    /** @param list<string> $failingSubjects */
    public static function text(string $code, array $failingSubjects = []): string
    {
        return str_replace('{subjects}', self::joinSubjects($failingSubjects), self::TEXTS[$code]);
    }

    public static function isKnown(string $code): bool
    {
        return isset(self::TEXTS[$code]);
    }

    /** @param list<string> $subjects */
    public static function joinSubjects(array $subjects): string
    {
        if (\count($subjects) <= 1) {
            return $subjects[0] ?? '';
        }
        $last = array_pop($subjects);

        return implode(', ', $subjects).' and '.$last;
    }
}
