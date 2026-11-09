<?php

declare(strict_types=1);

namespace App\Staff\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;
use App\Staff\Domain\Model\PdsEducation;
use App\Staff\Domain\Model\PdsEligibility;
use App\Staff\Domain\Model\PdsTraining;
use App\Staff\Domain\Model\PdsVoluntaryWork;
use App\Staff\Domain\Model\PdsWorkExperience;
use App\Staff\Domain\Model\PersonalDataSheet;

/** One list tab (III–VII) as the person saves it; $tab names the list. Its rows are checked by the tab's form. */
final class SavePdsListCommand implements UseCase
{
    /** Each tab and the property holding its rows. */
    public const TABS = [
        'education' => 'educations',
        'eligibility' => 'eligibilities',
        'work' => 'workExperiences',
        'training' => 'trainings',
        'voluntary' => 'voluntaryWorks',
    ];

    public int $actorId = 0;

    public string $tab = '';

    /** @var array<int, PdsEducationRow> keyed as on the form */
    public array $educations = [];

    /** @var array<int, PdsEligibilityRow> keyed as on the form */
    public array $eligibilities = [];

    /** @var array<int, PdsWorkExperienceRow> keyed as on the form */
    public array $workExperiences = [];

    /** @var array<int, PdsTrainingRow> keyed as on the form */
    public array $trainings = [];

    /** @var array<int, PdsVoluntaryWorkRow> keyed as on the form */
    public array $voluntaryWorks = [];

    public static function fromSheet(PersonalDataSheet $sheet, int $actorId, string $tab): self
    {
        $command = new self();
        $command->actorId = $actorId;
        $command->tab = $tab;
        switch ($tab) {
            case 'education':
                foreach (array_values($sheet->getEducations()->toArray()) as $i => $row) {
                    $command->educations[$i] = PdsEducationRow::fromEntity($row);
                }
                break;
            case 'eligibility':
                foreach (array_values($sheet->getEligibilities()->toArray()) as $i => $row) {
                    $command->eligibilities[$i] = PdsEligibilityRow::fromEntity($row);
                }
                break;
            case 'work':
                foreach (array_values($sheet->getWorkExperiences()->toArray()) as $i => $row) {
                    $command->workExperiences[$i] = PdsWorkExperienceRow::fromEntity($row);
                }
                break;
            case 'training':
                foreach (array_values($sheet->getTrainings()->toArray()) as $i => $row) {
                    $command->trainings[$i] = PdsTrainingRow::fromEntity($row);
                }
                break;
            case 'voluntary':
                foreach (array_values($sheet->getVoluntaryWorks()->toArray()) as $i => $row) {
                    $command->voluntaryWorks[$i] = PdsVoluntaryWorkRow::fromEntity($row);
                }
                break;
        }

        return $command;
    }

    /** Writes this tab's rows onto the sheet, by their keys on the form. */
    public function applyTo(PersonalDataSheet $sheet): void
    {
        switch ($this->tab) {
            case 'education':
                $existing = array_values($sheet->getEducations()->toArray());
                foreach ($existing as $i => $row) {
                    if (!isset($this->educations[$i])) {
                        $sheet->removeEducation($row);
                    }
                }
                foreach ($this->educations as $i => $input) {
                    $row = $existing[$i] ?? new PdsEducation();
                    $input->applyTo($row);
                    $sheet->addEducation($row);
                }
                break;
            case 'eligibility':
                $existing = array_values($sheet->getEligibilities()->toArray());
                foreach ($existing as $i => $row) {
                    if (!isset($this->eligibilities[$i])) {
                        $sheet->removeEligibility($row);
                    }
                }
                foreach ($this->eligibilities as $i => $input) {
                    $row = $existing[$i] ?? new PdsEligibility();
                    $input->applyTo($row);
                    $sheet->addEligibility($row);
                }
                break;
            case 'work':
                $existing = array_values($sheet->getWorkExperiences()->toArray());
                foreach ($existing as $i => $row) {
                    if (!isset($this->workExperiences[$i])) {
                        $sheet->removeWorkExperience($row);
                    }
                }
                foreach ($this->workExperiences as $i => $input) {
                    $row = $existing[$i] ?? new PdsWorkExperience();
                    $input->applyTo($row);
                    $sheet->addWorkExperience($row);
                }
                break;
            case 'training':
                $existing = array_values($sheet->getTrainings()->toArray());
                foreach ($existing as $i => $row) {
                    if (!isset($this->trainings[$i])) {
                        $sheet->removeTraining($row);
                    }
                }
                foreach ($this->trainings as $i => $input) {
                    $row = $existing[$i] ?? new PdsTraining();
                    $input->applyTo($row);
                    $sheet->addTraining($row);
                }
                break;
            case 'voluntary':
                $existing = array_values($sheet->getVoluntaryWorks()->toArray());
                foreach ($existing as $i => $row) {
                    if (!isset($this->voluntaryWorks[$i])) {
                        $sheet->removeVoluntaryWork($row);
                    }
                }
                foreach ($this->voluntaryWorks as $i => $input) {
                    $row = $existing[$i] ?? new PdsVoluntaryWork();
                    $input->applyTo($row);
                    $sheet->addVoluntaryWork($row);
                }
                break;
        }
    }

    public function toLogArray(): array
    {
        $rows = self::TABS[$this->tab] ?? null;

        return [
            'actorId' => $this->actorId,
            'tab' => $this->tab,
            'rows' => null === $rows ? 0 : \count($this->{$rows}),
        ];
    }
}
