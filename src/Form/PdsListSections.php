<?php

namespace App\Form;

/**
 * Wording and form setup of each PDS list tab, shared by ProfileController
 * and templates/profile/list_section.html.twig.
 */
final class PdsListSections
{
    /**
     * @var array<string, array{
     *     field: string, entry_type: class-string, heading: string, intro: string,
     *     add: string, empty: string, save: string, saved: string, row: string,
     *     datalists?: array<string, list<string>>
     * }>
     */
    private const SECTIONS = [
        'education' => [
            'field' => 'educations',
            'entry_type' => PdsEducationFormType::class,
            'heading' => 'III. Educational Background',
            'intro' => 'List every school, from elementary to graduate studies. Add another row for a second college or degree.',
            'add' => '+ Add school',
            'empty' => 'No schools listed yet.',
            'save' => 'Save education',
            'saved' => 'Educational background saved.',
            'row' => 'School',
        ],
        'eligibility' => [
            'field' => 'eligibilities',
            'entry_type' => PdsEligibilityFormType::class,
            'heading' => 'IV. Civil Service Eligibility',
            'intro' => 'Include the LET (RA 1080) and any other eligibility, board/bar rating or license.',
            'add' => '+ Add eligibility',
            'empty' => 'No eligibility listed yet.',
            'save' => 'Save eligibility',
            'saved' => 'Civil service eligibility saved.',
            'row' => 'Eligibility',
            'datalists' => [
                'eligibility-names' => [
                    'Professional Teacher (RA 1080 / LET)',
                    'Career Service Professional',
                    'Career Service Sub-Professional',
                    'Bar (RA 1080)',
                    'Board (RA 1080)',
                    'Barangay Eligibility',
                    'Driver\'s License',
                ],
            ],
        ],
        'work' => [
            'field' => 'workExperiences',
            'entry_type' => PdsWorkExperienceFormType::class,
            'heading' => 'V. Work Experience',
            'intro' => 'Include private employment. Your current job is listed first, then the most recent. Describe your duties in the separate Work Experience Sheet.',
            'add' => '+ Add work experience',
            'empty' => 'No work experience listed yet.',
            'save' => 'Save work experience',
            'saved' => 'Work experience saved.',
            'row' => 'Work experience',
            'datalists' => [
                'appointment-statuses' => ['Permanent', 'Temporary', 'Substitute', 'Provisional', 'Contractual', 'Casual', 'Coterminous', 'Job Order', 'Contract of Service'],
            ],
        ],
        'training' => [
            'field' => 'trainings',
            'entry_type' => PdsTrainingFormType::class,
            'heading' => 'VI. Learning and Development (L&D) Interventions / Training Programs Attended',
            'intro' => 'Include INSET, LAC sessions, seminars and trainings. The most recent is listed first.',
            'add' => '+ Add training',
            'empty' => 'No trainings listed yet.',
            'save' => 'Save learning and development',
            'saved' => 'Learning and development saved.',
            'row' => 'Training',
            'datalists' => [
                'training-types' => ['Managerial', 'Supervisory', 'Technical', 'Foundation'],
            ],
        ],
        'voluntary' => [
            'field' => 'voluntaryWorks',
            'entry_type' => PdsVoluntaryWorkFormType::class,
            'heading' => 'VII. Voluntary Work or Involvement in Civic / Non-Government / People / Voluntary Organizations',
            'intro' => 'For example Brigada Eskwela, church or community organizations. The most recent is listed first.',
            'add' => '+ Add voluntary work',
            'empty' => 'No voluntary work listed yet.',
            'save' => 'Save voluntary work',
            'saved' => 'Voluntary work saved.',
            'row' => 'Voluntary work',
        ],
    ];

    /** @return array<string, mixed> */
    public static function get(string $key): array
    {
        return self::SECTIONS[$key] ?? throw new \InvalidArgumentException(\sprintf('Unknown PDS list section "%s".', $key));
    }
}
