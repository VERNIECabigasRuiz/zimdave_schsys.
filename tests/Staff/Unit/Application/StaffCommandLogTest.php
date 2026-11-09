<?php

declare(strict_types=1);

namespace App\Tests\Staff\Unit\Application;

use App\Staff\Application\Query\Command\GetMyIdCommand;
use App\Staff\Application\Query\Command\GetServiceRecordCommand;
use App\Staff\Application\Query\Command\GetServiceRecordPdfCommand;
use App\Staff\Application\Query\Command\GetServiceRowFormCommand;
use App\Staff\Application\Query\Command\GetStaffIdPdfCommand;
use App\Staff\Application\UseCase\Command\DeleteServiceRowCommand;
use App\Staff\Application\UseCase\Command\DownloadTeacherPdsCommand;
use App\Staff\Application\UseCase\Command\OpenPersonalDataSheetCommand;
use App\Staff\Application\UseCase\Command\PdsChildRow;
use App\Staff\Application\UseCase\Command\PdsEducationRow;
use App\Staff\Application\UseCase\Command\PdsReferenceRow;
use App\Staff\Application\UseCase\Command\SaveFamilyBackgroundCommand;
use App\Staff\Application\UseCase\Command\SaveOtherInfoCommand;
use App\Staff\Application\UseCase\Command\SavePdsListCommand;
use App\Staff\Application\UseCase\Command\SavePersonalInfoCommand;
use App\Staff\Application\UseCase\Command\SaveQuestionsCommand;
use App\Staff\Application\UseCase\Command\SaveBpNumberCommand;
use App\Staff\Application\UseCase\Command\SaveReferencesCommand;
use App\Staff\Application\UseCase\Command\SaveServiceRowCommand;
use App\Staff\Application\UseCase\Command\SaveStaffIdDetailsCommand;
use App\Staff\Domain\Service\StaffId\StaffIdSize;
use PHPUnit\Framework\TestCase;

final class StaffCommandLogTest extends TestCase
{
    public function testNoPersonalDataIsLogged(): void
    {
        // Initialize
        $personal = new SavePersonalInfoCommand();
        $personal->actorId = 4;
        $personal->surname = 'Dela Cruz';
        $personal->tin = '123-456-789';
        $family = new SaveFamilyBackgroundCommand();
        $family->actorId = 4;
        $family->spouseSurname = 'Reyes';
        $family->children = [new PdsChildRow(), new PdsChildRow()];
        $other = new SaveOtherInfoCommand();
        $other->actorId = 4;
        $other->skills = ['Chess'];
        $questions = new SaveQuestionsCommand();
        $questions->actorId = 4;
        $questions->q36Details = 'A case';
        $references = new SaveReferencesCommand();
        $references->actorId = 4;
        $references->govIdNumber = 'P1234567';
        $references->references = [new PdsReferenceRow()];
        $list = new SavePdsListCommand();
        $list->actorId = 4;
        $list->tab = 'education';
        $list->educations = [new PdsEducationRow()];
        $unknownTab = new SavePdsListCommand();
        $unknownTab->tab = 'nonsense';
        $commands = [$personal, $family, $other, $questions, $references, $list, $unknownTab,
            new OpenPersonalDataSheetCommand(4), new DownloadTeacherPdsCommand(2, 7, 4)];

        // Execute
        $logged = array_map(static fn ($command) => $command->toLogArray(), $commands);

        // Assert
        self::assertSame([
            ['actorId' => 4, 'tab' => 'personal'],
            ['actorId' => 4, 'tab' => 'family', 'children' => 2],
            ['actorId' => 4, 'tab' => 'other', 'skills' => 1, 'distinctions' => 0, 'memberships' => 0],
            ['actorId' => 4, 'tab' => 'questions'],
            ['actorId' => 4, 'tab' => 'references', 'references' => 1],
            ['actorId' => 4, 'tab' => 'education', 'rows' => 1],
            ['actorId' => 0, 'tab' => 'nonsense', 'rows' => 0],
            ['actorId' => 4],
            ['actorId' => 2, 'schoolId' => 7, 'teacherId' => 4],
        ], $logged);
    }

    public function testNoServiceRecordOrIdDataIsLogged(): void
    {
        // Initialize
        $row = new SaveServiceRowCommand();
        $row->actorId = 2;
        $row->schoolId = 7;
        $row->employeeId = 4;
        $row->entryId = 9;
        $row->designation = 'Teacher III';
        $row->annualSalary = '443028.00';
        $row->closeOpen = true;
        $details = new SaveStaffIdDetailsCommand();
        $details->actorId = 4;
        $details->emergencyName = 'Maria Dela Cruz';
        $details->gsisPolicyNo = '2003334189';
        $commands = [
            $row,
            $details,
            new SaveBpNumberCommand(2, 7, 4, '2003334189'),
            new DeleteServiceRowCommand(2, 7, 4, 9),
            new GetServiceRecordCommand(2, 7, 4),
            new GetServiceRowFormCommand(2, 7, 4),
            new GetServiceRecordPdfCommand(2, 7, 4),
            new GetMyIdCommand(4, $details),
            new GetStaffIdPdfCommand(4, StaffIdSize::Small),
        ];

        // Execute
        $logged = array_map(static fn ($command) => $command->toLogArray(), $commands);

        // Assert
        self::assertSame([
            ['actorId' => 2, 'schoolId' => 7, 'employeeId' => 4, 'entryId' => 9, 'closeOpen' => true],
            ['actorId' => 4],
            ['actorId' => 2, 'schoolId' => 7, 'employeeId' => 4],
            ['actorId' => 2, 'schoolId' => 7, 'employeeId' => 4, 'entryId' => 9],
            ['actorId' => 2, 'schoolId' => 7, 'employeeId' => 4],
            ['actorId' => 2, 'schoolId' => 7, 'employeeId' => 4, 'entryId' => null],
            ['actorId' => 2, 'schoolId' => 7, 'employeeId' => 4],
            ['actorId' => 4, 'typed' => true],
            ['actorId' => 4, 'size' => 'small'],
        ], $logged);
    }
}
