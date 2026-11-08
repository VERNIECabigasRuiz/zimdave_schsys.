<?php

declare(strict_types=1);

namespace App\Staff\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;
use App\Staff\Domain\Model\PersonalDataSheet;
use Symfony\Component\Validator\Constraints as Assert;

/** The answers to questions 34–40 as the person saves them. */
final class SaveQuestionsCommand implements UseCase
{
    public int $actorId = 0;

    public ?bool $q34a = null;

    public ?bool $q34b = null;

    #[Assert\Length(max: 255, groups: [PersonalDataSheet::QUESTIONS])]
    #[Assert\Expression(
        '!this.detailMissing("q34Details")',
        message: 'You answered YES: give the details.',
        groups: [PersonalDataSheet::QUESTIONS],
    )]
    public ?string $q34Details = null;

    public ?bool $q35a = null;

    #[Assert\Length(max: 255, groups: [PersonalDataSheet::QUESTIONS])]
    #[Assert\Expression(
        '!this.detailMissing("q35aDetails")',
        message: 'You answered YES: give the details.',
        groups: [PersonalDataSheet::QUESTIONS],
    )]
    public ?string $q35aDetails = null;

    public ?bool $q35b = null;

    #[Assert\Length(max: 255, groups: [PersonalDataSheet::QUESTIONS])]
    #[Assert\Expression(
        '!this.detailMissing("q35bDetails")',
        message: 'You answered YES: give the details.',
        groups: [PersonalDataSheet::QUESTIONS],
    )]
    public ?string $q35bDetails = null;

    #[Assert\LessThanOrEqual(
        'today',
        message: 'The date cannot be in the future.',
        groups: [PersonalDataSheet::QUESTIONS],
    )]
    #[Assert\Expression(
        '!this.detailMissing("q35bDateFiled")',
        message: 'Enter the date filed.',
        groups: [PersonalDataSheet::QUESTIONS],
    )]
    public ?\DateTimeImmutable $q35bDateFiled = null;

    #[Assert\Length(max: 100, groups: [PersonalDataSheet::QUESTIONS])]
    #[Assert\Expression(
        '!this.detailMissing("q35bStatus")',
        message: 'Enter the status of the case.',
        groups: [PersonalDataSheet::QUESTIONS],
    )]
    public ?string $q35bStatus = null;

    public ?bool $q36 = null;

    #[Assert\Length(max: 255, groups: [PersonalDataSheet::QUESTIONS])]
    #[Assert\Expression(
        '!this.detailMissing("q36Details")',
        message: 'You answered YES: give the details.',
        groups: [PersonalDataSheet::QUESTIONS],
    )]
    public ?string $q36Details = null;

    public ?bool $q37 = null;

    #[Assert\Length(max: 255, groups: [PersonalDataSheet::QUESTIONS])]
    #[Assert\Expression(
        '!this.detailMissing("q37Details")',
        message: 'You answered YES: give the details.',
        groups: [PersonalDataSheet::QUESTIONS],
    )]
    public ?string $q37Details = null;

    public ?bool $q38a = null;

    #[Assert\Length(max: 255, groups: [PersonalDataSheet::QUESTIONS])]
    #[Assert\Expression(
        '!this.detailMissing("q38aDetails")',
        message: 'You answered YES: give the details.',
        groups: [PersonalDataSheet::QUESTIONS],
    )]
    public ?string $q38aDetails = null;

    public ?bool $q38b = null;

    #[Assert\Length(max: 255, groups: [PersonalDataSheet::QUESTIONS])]
    #[Assert\Expression(
        '!this.detailMissing("q38bDetails")',
        message: 'You answered YES: give the details.',
        groups: [PersonalDataSheet::QUESTIONS],
    )]
    public ?string $q38bDetails = null;

    public ?bool $q39 = null;

    /** Country. */
    #[Assert\Length(max: 100, groups: [PersonalDataSheet::QUESTIONS])]
    #[Assert\Expression(
        '!this.detailMissing("q39Details")',
        message: 'Enter the country.',
        groups: [PersonalDataSheet::QUESTIONS],
    )]
    public ?string $q39Details = null;

    public ?bool $q40a = null;

    /** Indigenous group. */
    #[Assert\Length(max: 150, groups: [PersonalDataSheet::QUESTIONS])]
    #[Assert\Expression(
        '!this.detailMissing("q40aDetails")',
        message: 'You answered YES: give the details.',
        groups: [PersonalDataSheet::QUESTIONS],
    )]
    public ?string $q40aDetails = null;

    public ?bool $q40b = null;

    /** PWD ID no. */
    #[Assert\Length(max: 50, groups: [PersonalDataSheet::QUESTIONS])]
    #[Assert\Expression(
        '!this.detailMissing("q40bDetails")',
        message: 'You answered YES: give the details.',
        groups: [PersonalDataSheet::QUESTIONS],
    )]
    public ?string $q40bDetails = null;

    public ?bool $q40c = null;

    /** Solo parent ID no. */
    #[Assert\Length(max: 50, groups: [PersonalDataSheet::QUESTIONS])]
    #[Assert\Expression(
        '!this.detailMissing("q40cDetails")',
        message: 'You answered YES: give the details.',
        groups: [PersonalDataSheet::QUESTIONS],
    )]
    public ?string $q40cDetails = null;

    public static function fromSheet(PersonalDataSheet $sheet, int $actorId): self
    {
        $command = new self();
        $command->actorId = $actorId;
        $command->q34a = $sheet->q34a;
        $command->q34b = $sheet->q34b;
        $command->q34Details = $sheet->q34Details;
        $command->q35a = $sheet->q35a;
        $command->q35aDetails = $sheet->q35aDetails;
        $command->q35b = $sheet->q35b;
        $command->q35bDetails = $sheet->q35bDetails;
        $command->q35bDateFiled = $sheet->q35bDateFiled;
        $command->q35bStatus = $sheet->q35bStatus;
        $command->q36 = $sheet->q36;
        $command->q36Details = $sheet->q36Details;
        $command->q37 = $sheet->q37;
        $command->q37Details = $sheet->q37Details;
        $command->q38a = $sheet->q38a;
        $command->q38aDetails = $sheet->q38aDetails;
        $command->q38b = $sheet->q38b;
        $command->q38bDetails = $sheet->q38bDetails;
        $command->q39 = $sheet->q39;
        $command->q39Details = $sheet->q39Details;
        $command->q40a = $sheet->q40a;
        $command->q40aDetails = $sheet->q40aDetails;
        $command->q40b = $sheet->q40b;
        $command->q40bDetails = $sheet->q40bDetails;
        $command->q40c = $sheet->q40c;
        $command->q40cDetails = $sheet->q40cDetails;

        return $command;
    }

    /** Writes this tab's fields onto the sheet. */
    public function applyTo(PersonalDataSheet $sheet): void
    {
        $sheet->q34a = $this->q34a;
        $sheet->q34b = $this->q34b;
        $sheet->q34Details = $this->q34Details;
        $sheet->q35a = $this->q35a;
        $sheet->q35aDetails = $this->q35aDetails;
        $sheet->q35b = $this->q35b;
        $sheet->q35bDetails = $this->q35bDetails;
        $sheet->q35bDateFiled = $this->q35bDateFiled;
        $sheet->q35bStatus = $this->q35bStatus;
        $sheet->q36 = $this->q36;
        $sheet->q36Details = $this->q36Details;
        $sheet->q37 = $this->q37;
        $sheet->q37Details = $this->q37Details;
        $sheet->q38a = $this->q38a;
        $sheet->q38aDetails = $this->q38aDetails;
        $sheet->q38b = $this->q38b;
        $sheet->q38bDetails = $this->q38bDetails;
        $sheet->q39 = $this->q39;
        $sheet->q39Details = $this->q39Details;
        $sheet->q40a = $this->q40a;
        $sheet->q40aDetails = $this->q40aDetails;
        $sheet->q40b = $this->q40b;
        $sheet->q40bDetails = $this->q40bDetails;
        $sheet->q40c = $this->q40c;
        $sheet->q40cDetails = $this->q40cDetails;
    }

    /** @param list<string> $answers */
    private function anyYes(array $answers): bool
    {
        foreach ($answers as $answer) {
            if (true === $this->{$answer}) {
                return true;
            }
        }

        return false;
    }

    /** A question was answered YES but $field, one of its "If YES" details, is empty. */
    public function detailMissing(string $field): bool
    {
        $answers = [];
        foreach (PersonalDataSheet::QUESTION_DETAILS as [$questionAnswers, $details]) {
            if (\in_array($field, $details, true)) {
                $answers = $questionAnswers;
            }
        }

        return $this->anyYes($answers) && (null === $this->{$field} || '' === $this->{$field});
    }

    public function toLogArray(): array
    {
        return ['actorId' => $this->actorId, 'tab' => 'questions'];
    }
}
