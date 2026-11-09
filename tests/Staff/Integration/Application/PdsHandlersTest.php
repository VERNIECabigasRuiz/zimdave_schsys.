<?php

declare(strict_types=1);

namespace App\Tests\Staff\Integration\Application;

use App\Access\Domain\Model\User;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Core\Domain\Model\ActivityLog;
use App\Learners\Domain\Model\SexAtBirth;
use App\Staff\Application\Query\Command\GetPdsPdfCommand;
use App\Staff\Application\Query\Command\GetPersonalDataSheetCommand;
use App\Staff\Application\UseCase\Command\DownloadTeacherPdsCommand;
use App\Staff\Application\UseCase\Command\OpenPersonalDataSheetCommand;
use App\Staff\Application\UseCase\Command\PdsReferenceRow;
use App\Staff\Application\UseCase\Command\PdsTrainingRow;
use App\Staff\Application\UseCase\Command\SaveFamilyBackgroundCommand;
use App\Staff\Application\UseCase\Command\SaveOtherInfoCommand;
use App\Staff\Application\UseCase\Command\SavePdsListCommand;
use App\Staff\Application\UseCase\Command\SavePersonalInfoCommand;
use App\Staff\Application\UseCase\Command\SaveQuestionsCommand;
use App\Staff\Application\UseCase\Command\SaveReferencesCommand;
use App\Staff\Domain\Exception\StaffNotFoundException;
use App\Staff\Domain\Model\Citizenship;
use App\Staff\Domain\Model\EducationLevel;
use App\Staff\Domain\Model\PdsChild;
use App\Staff\Domain\Model\PdsEducation;
use App\Staff\Domain\Model\PdsEligibility;
use App\Staff\Domain\Model\PdsReference;
use App\Staff\Domain\Model\CivilStatus;
use App\Staff\Domain\Model\PdsTraining;
use App\Staff\Domain\Model\PdsVoluntaryWork;
use App\Staff\Domain\Model\PdsWorkExperience;
use App\Staff\Domain\Model\PersonalDataSheet;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PdsHandlersTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private User $maria;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->maria = $this->plainUser('maria', User::ROLE_TEACHER)->setFullName('Maria');
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    public function testOpeningStartsTheSheetOnceAndTheQueriesReadIt(): void
    {
        // Initialize
        $open = new OpenPersonalDataSheetCommand($this->id());

        // Execute
        $first = $this->handle($open);
        $again = $this->handle($open);
        $page = $this->query(new GetPersonalDataSheetCommand($this->id()));
        $pdf = $this->query(new GetPdsPdfCommand($this->id()));

        // Assert
        self::assertTrue($first->isSuccess() && $again->isSuccess());
        self::assertSame(1, $this->rows('personal_data_sheet'));
        $data = $page->data();
        self::assertIsArray($data);
        self::assertInstanceOf(PersonalDataSheet::class, $data['sheet']);
        self::assertSame($this->id(), $data['sheet']->getUser()->getId());
        $file = $pdf->data();
        self::assertIsArray($file);
        self::assertIsString($file['content']);
        self::assertStringStartsWith('%PDF', $file['content']);
        self::assertSame('PDS.pdf', $file['fileName']);
    }

    public function testATabSavesOnlyItsOwnFieldsDatesTheSheetAndNamesTheAccount(): void
    {
        // Initialize
        $personal = $this->personalInfo();
        $personal->surname = 'Santos';
        $personal->firstName = 'Maria Clara';
        $personal->middleName = 'luna';
        $personal->resCity = 'Mandaue City';
        $personal->permSameAsResidential = true;

        // Execute
        $saved = $this->handle($personal);
        $family = SaveFamilyBackgroundCommand::fromSheet($this->sheet(), $this->id());
        $family->spouseSurname = 'Reyes';
        $savedFamily = $this->handle($family);

        // Assert
        self::assertTrue($saved->isSuccess() && $savedFamily->isSuccess());
        $sheet = $this->sheet();
        self::assertSame(
            ['Santos', 'Mandaue City', 'Mandaue City', 'Reyes'],
            [$sheet->surname, $sheet->resCity, $sheet->permCity, $sheet->spouseSurname],
        );
        self::assertNotNull($sheet->getUpdatedAt());
        self::assertSame('Maria Clara L. Santos', $this->found(User::class, $this->id())->getFullName());
        self::assertSame(2, $this->logged('Updated own profile'));
    }

    public function testAVeryLongNameIsCutForTheAccount(): void
    {
        // Initialize
        $personal = $this->personalInfo();
        $personal->surname = str_repeat('S', 80);
        $personal->firstName = str_repeat('F', 80);

        // Execute
        $this->handle($personal);

        // Assert
        self::assertSame(120, mb_strlen($this->found(User::class, $this->id())->getFullName()));
    }

    public function testListRowsAreUpdatedAddedAndRemovedByTheirKeys(): void
    {
        // Initialize
        $this->handle(new OpenPersonalDataSheetCommand($this->id()));
        $sheet = $this->sheet();
        foreach (['2024-01-10' => 'INSET', '2023-05-02' => 'LAC', '2022-08-15' => 'Seminar'] as $from => $title) {
            $training = new PdsTraining();
            $training->title = $title;
            $training->dateFrom = new \DateTimeImmutable($from);
            $sheet->addTraining($training);
        }
        $this->em->flush();
        $list = SavePdsListCommand::fromSheet($this->sheet(), $this->id(), 'training');
        unset($list->trainings[1]);
        $list->trainings[0]->title = 'INSET 2024';
        $added = new PdsTrainingRow();
        $added->title = 'Webinar';
        $added->dateFrom = new \DateTimeImmutable('2025-02-01');
        $list->trainings[3] = $added;

        // Execute
        $result = $this->handle($list);

        // Assert
        self::assertTrue($result->isSuccess());
        $titles = array_map(static fn (PdsTraining $t) => [$t->position, $t->title], $this->sheet()->sortedTrainings());
        self::assertSame([[0, 'Webinar'], [1, 'INSET 2024'], [2, 'Seminar']], $titles);
    }

    public function testARemovedRowIsDeletedAndTheKeptRowIsUpdatedInPlace(): void
    {
        // Initialize
        $this->handle(new OpenPersonalDataSheetCommand($this->id()));
        $sheet = $this->sheet();
        foreach ([0, 1] as $i) {
            $education = new PdsEducation();
            $education->level = EducationLevel::College;
            $education->schoolName = 'School ' . $i;
            $education->yearFrom = 2000 + $i;
            $sheet->addEducation($education);
            $eligibility = new PdsEligibility();
            $eligibility->name = 'Eligibility ' . $i;
            $sheet->addEligibility($eligibility);
            $job = new PdsWorkExperience();
            $job->positionTitle = 'Job ' . $i;
            $job->office = 'Office';
            $job->dateFrom = new \DateTimeImmutable('201' . $i . '-06-01');
            $sheet->addWorkExperience($job);
            $training = new PdsTraining();
            $training->title = 'Training ' . $i;
            $training->dateFrom = new \DateTimeImmutable('201' . $i . '-06-01');
            $sheet->addTraining($training);
            $voluntary = new PdsVoluntaryWork();
            $voluntary->organization = 'Organization ' . $i;
            $voluntary->dateFrom = new \DateTimeImmutable('201' . $i . '-06-01');
            $sheet->addVoluntaryWork($voluntary);
            $child = new PdsChild();
            $child->fullName = 'Child ' . $i;
            $sheet->addChild($child);
        }
        foreach ([0, 1, 2] as $i) {
            $reference = new PdsReference();
            $reference->name = 'Reference ' . $i;
            $reference->address = 'Mandaue City';
            $reference->contact = '09171234567';
            $sheet->addReference($reference);
        }
        $sheet->renumberLists();
        $this->em->flush();
        $sheet = $this->sheet();
        $lists = [
            'education' => fn (): array => array_values($this->sheet()->getEducations()->toArray()),
            'eligibility' => fn (): array => array_values($this->sheet()->getEligibilities()->toArray()),
            'work' => fn (): array => array_values($this->sheet()->getWorkExperiences()->toArray()),
            'training' => fn (): array => array_values($this->sheet()->getTrainings()->toArray()),
            'voluntary' => fn (): array => array_values($this->sheet()->getVoluntaryWorks()->toArray()),
        ];
        $kept = [];
        foreach ($lists as $tab => $rows) {
            $kept[$tab] = $rows()[1]->getId();
        }
        $kept['children'] = $sheet->getChildren()->toArray()[1]->getId();
        $referenceIds = array_map(static fn (PdsReference $r): ?int => $r->getId(), $sheet->getReferences()->toArray());

        // Execute
        foreach (array_keys($lists) as $tab) {
            $list = SavePdsListCommand::fromSheet($this->sheet(), $this->id(), $tab);
            $property = SavePdsListCommand::TABS[$tab];
            unset($list->{$property}[0]);
            $this->handle($list);
        }
        $family = SaveFamilyBackgroundCommand::fromSheet($this->sheet(), $this->id());
        unset($family->children[0]);
        $this->handle($family);
        $renamed = SaveReferencesCommand::fromSheet($this->sheet(), $this->id());
        $renamed->references[0]->name = 'Dr. Ana Reyes';
        $this->handle($renamed);
        $renamedIds = array_map(
            static fn (PdsReference $r): ?int => $r->getId(),
            $this->sheet()->getReferences()->toArray(),
        );
        $references = SaveReferencesCommand::fromSheet($this->sheet(), $this->id());
        $references->references = [];
        $this->handle($references);

        // Assert
        $left = [];
        foreach ($lists as $tab => $rows) {
            $left[$tab] = array_map(static fn (object $row): ?int => $row->getId(), $rows());
        }
        $left['children'] = array_map(
            static fn (PdsChild $child): ?int => $child->getId(),
            array_values($this->sheet()->getChildren()->toArray()),
        );
        self::assertSame(array_map(static fn (?int $id): array => [$id], $kept), $left);
        self::assertSame($referenceIds, $renamedIds);
        self::assertSame([], array_map(
            static fn (PdsReference $reference): ?int => $reference->getId(),
            $this->sheet()->getReferences()->toArray(),
        ));
    }

    public function testEachTabRunsItsFollowUp(): void
    {
        // Initialize
        $this->handle(new OpenPersonalDataSheetCommand($this->id()));
        $questions = SaveQuestionsCommand::fromSheet($this->sheet(), $this->id());
        $questions->q36 = false;
        $questions->q36Details = 'Old details';
        $references = SaveReferencesCommand::fromSheet($this->sheet(), $this->id());
        foreach ($references->references as $i => $row) {
            $row->name = 'Reference ' . $i;
            $row->address = 'Mandaue City';
            $row->contact = '09171234567';
        }
        $references->references[3] = new PdsReferenceRow();
        $other = SaveOtherInfoCommand::fromSheet($this->sheet(), $this->id());
        $other->skills = [2 => 'Chess', 5 => 'Choir'];

        // Execute
        $this->handle($questions);
        $this->handle($references);
        $this->handle($other);

        // Assert
        $sheet = $this->sheet();
        self::assertNull($sheet->q36Details);
        self::assertCount(3, $sheet->getReferences());
        self::assertSame(['Chess', 'Choir'], $sheet->skills);
    }

    public function testTheSchoolDownloadsATeachersPdsOnlyThisYear(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, current: true);
        $school = $this->school('303196', 'Pagsabungan NHS');
        $other = $this->school('303197', 'Other NHS');
        $this->assign($school, $sy, $this->maria, User::ROLE_TEACHER);
        $rene = $this->plainUser('rene', User::ROLE_PRINCIPAL);
        $download = fn (int $schoolId) => $this->handle(
            new DownloadTeacherPdsCommand((int) $rene->getId(), $schoolId, $this->id()),
        );

        // Execute
        $mine = $download((int) $school->getId());
        $elsewhere = $download((int) $other->getId());
        $unknownSchool = $download(-1);
        $sy->setCurrent(false);
        $this->em->flush();
        $noYear = $download((int) $school->getId());

        // Assert
        $file = $mine->data();
        self::assertIsArray($file);
        self::assertIsString($file['content']);
        self::assertStringStartsWith('%PDF', $file['content']);
        self::assertSame(1, $this->logged('Downloaded PDS of maria'));
        self::assertSame(0, $this->rows('personal_data_sheet'));
        self::assertSame(
            [
                'This teacher does not belong to the school this school year.',
                'Not Found',
                'No current school year.',
            ],
            [
                $elsewhere->exception()?->getMessage(),
                $unknownSchool->exception()?->getMessage(),
                $noYear->exception()?->getMessage(),
            ],
        );
        self::assertInstanceOf(StaffNotFoundException::class, $elsewhere->exception());
    }

    public function testATeacherPromotedSinceIsNoLongerTheSchoolsTeacher(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, current: true);
        $school = $this->school('303196', 'Pagsabungan NHS');
        $this->assign($school, $sy, $this->maria, User::ROLE_TEACHER);
        $rene = $this->plainUser('rene', User::ROLE_PRINCIPAL);
        $this->maria->setRole(User::ROLE_PRINCIPAL);
        $this->em->flush();

        // Execute
        $result = $this->handle(
            new DownloadTeacherPdsCommand((int) $rene->getId(), (int) $school->getId(), $this->id()),
        );

        // Assert
        self::assertSame(
            'This teacher does not belong to the school this school year.',
            $result->exception()?->getMessage(),
        );
        self::assertSame(0, $this->logged('Downloaded PDS of maria'));
    }

    /** The Personal Information tab with its required fields filled. */
    private function personalInfo(): SavePersonalInfoCommand
    {
        $personal = SavePersonalInfoCommand::fromSheet(new PersonalDataSheet($this->maria), $this->id());
        $personal->surname = 'Santos';
        $personal->firstName = 'Maria';
        $personal->dateOfBirth = new \DateTimeImmutable('1985-03-14');
        $personal->sexAtBirth = SexAtBirth::Female;
        $personal->civilStatus = CivilStatus::Married;
        $personal->citizenship = Citizenship::Filipino;

        return $personal;
    }

    private function id(): int
    {
        return (int) $this->maria->getId();
    }

    private function sheet(): PersonalDataSheet
    {
        $this->em->clear();
        $sheet = $this->em->getRepository(PersonalDataSheet::class)->findOneBy(['user' => $this->id()]);
        self::assertNotNull($sheet);

        return $sheet;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function found(string $class, int $id): object
    {
        $this->em->clear();
        $entity = $this->em->find($class, $id);
        self::assertNotNull($entity);

        return $entity;
    }

    private function logged(string $action): int
    {
        return \count($this->em->getRepository(ActivityLog::class)->findBy(['action' => $action]));
    }

    private function rows(string $table): int
    {
        $count = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function handle(UseCase $command): Result
    {
        return static::getContainer()->get(UseCaseBusInterface::class)->handle($command);
    }

    private function query(Query $command): Result
    {
        return static::getContainer()->get(QueryBusInterface::class)->handle($command);
    }
}
