<?php

declare(strict_types=1);

namespace App\Tests\Staff\Integration\Application;

use App\Access\Domain\Model\User;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Schools\Domain\Model\Division;
use App\Schools\Domain\Model\School;
use App\Staff\Application\Query\Command\GetMyIdCommand;
use App\Staff\Application\Query\Command\GetStaffIdPdfCommand;
use App\Staff\Application\UseCase\Command\SaveStaffIdDetailsCommand;
use App\Staff\Domain\Exception\StaffAccessDeniedException;
use App\Staff\Domain\Exception\StaffIdNotPrintableException;
use App\Staff\Domain\Model\ServiceRecordEntry;
use App\Staff\Domain\Model\StaffIdDetails;
use App\Staff\Domain\Service\StaffId\StaffIdCard;
use App\Staff\Domain\Service\StaffId\StaffIdSize;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class StaffIdHandlersTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private School $school;
    private User $rosa;
    private User $tess;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2026-11-02 10:00:00');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303196', 'Pagsabungan NHS');
        $this->rosa = $this->plainUser('rosa', User::ROLE_PRINCIPAL)->setFullName('Rosa Cruz');
        $this->assign($this->school, $sy, $this->rosa);
        $this->tess = $this->plainUser('tess', User::ROLE_TEACHER)->setFullName('Tess Reyes');
        $this->assign($this->school, $sy, $this->tess, User::ROLE_TEACHER);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    public function testDetailsAreSavedWithBlanksAsNothing(): void
    {
        // Initialize
        $details = new SaveStaffIdDetailsCommand();
        $details->actorId = $this->tessId();
        $details->callName = ' Sir Jun ';
        $details->emergencyName = '';
        $details->emergencyContact = '0917 123 4567';
        $details->gsisPolicyNo = '2003334189';

        // Execute
        $result = $this->handle($details);

        // Assert
        self::assertTrue($result->isSuccess());
        $this->em->clear();
        $saved = $this->em->getRepository(StaffIdDetails::class)->findOneBy(['user' => $this->tessId()]);
        self::assertSame(
            ['Sir Jun', null, '0917 123 4567', '2003334189'],
            [
                $saved?->getCallName(),
                $saved?->getEmergencyName(),
                $saved?->getEmergencyContact(),
                $saved?->getGsisPolicyNo(),
            ],
        );
    }

    public function testOnlyTeachersAndPrincipalsWithASchoolHaveAnId(): void
    {
        // Initialize
        $unassigned = $this->plainUser('una', User::ROLE_TEACHER);
        $student = $this->plainUser('stu', User::ROLE_STUDENT);
        $save = new SaveStaffIdDetailsCommand();
        $save->actorId = (int) $unassigned->getId();

        // Execute
        $results = [
            $this->query(new GetMyIdCommand((int) $unassigned->getId())),
            $this->query(new GetMyIdCommand((int) $student->getId())),
            $this->handle($save),
            $this->query(new GetStaffIdPdfCommand((int) $unassigned->getId(), StaffIdSize::Big)),
        ];

        // Assert
        foreach ($results as $result) {
            self::assertInstanceOf(StaffAccessDeniedException::class, $result->exception());
            self::assertSame(
                'Only teachers and principals with a school this school year have an ID here.',
                $result->exception()->getMessage(),
            );
        }
        self::assertSame([], $this->em->getRepository(StaffIdDetails::class)->findAll());
    }

    public function testTheIdDoesNotPrintWithoutAPhotoAndAPosition(): void
    {
        // Initialize
        $pdf = new GetStaffIdPdfCommand($this->tessId(), StaffIdSize::Small);

        // Execute
        $result = $this->query($pdf);

        // Assert
        self::assertInstanceOf(StaffIdNotPrintableException::class, $result->exception());
        self::assertSame(
            'Add your photo and a current (Present) service record row before printing your ID.',
            $result->exception()->getMessage(),
        );
    }

    public function testThePdfAndItsFileName(): void
    {
        // Initialize
        $this->present($this->tess, 'Teacher I');
        $file = $this->photo($this->tess);

        // Execute
        try {
            $big = $this->query(new GetStaffIdPdfCommand($this->tessId(), StaffIdSize::Big));
        } finally {
            unlink($file);
        }

        // Assert
        $data = $big->data();
        self::assertIsArray($data);
        self::assertIsString($data['content']);
        self::assertStringStartsWith('%PDF', $data['content']);
        self::assertSame('ID (Big) - REYES, TESS.pdf', $data['fileName']);
    }

    public function testThePageShowsWhatWasTypedAfterARefusedSave(): void
    {
        // Initialize
        $this->em->persist((new StaffIdDetails($this->tess))->setCallName('Sir Jun'));
        $this->em->flush();
        $typed = new SaveStaffIdDetailsCommand();
        $typed->callName = 'Teacher T';
        $blank = new SaveStaffIdDetailsCommand();

        // Execute
        $saved = $this->query(new GetMyIdCommand($this->tessId()))->data();
        $shown = $this->query(new GetMyIdCommand($this->tessId(), $typed))->data();
        $cleared = $this->query(new GetMyIdCommand($this->tessId(), $blank))->data();

        // Assert
        self::assertIsArray($saved);
        self::assertIsArray($shown);
        self::assertIsArray($cleared);
        self::assertSame(
            ['Sir Jun', 'Teacher T', 'TESS'],
            [$this->card($saved)->callName, $this->card($shown)->callName, $this->card($cleared)->callName],
        );
        self::assertInstanceOf(StaffIdDetails::class, $shown['details']);
        self::assertSame('Sir Jun', $shown['details']->getCallName());
        self::assertSame([['photo', 'position'], false, false, null], [
            $saved['missing'],
            $saved['canPrint'],
            $saved['isPrincipal'],
            $saved['division'],
        ]);
        self::assertInstanceOf(School::class, $saved['school']);
        self::assertSame($this->school->getId(), $saved['school']->getId());
    }

    public function testWhatWasTypedIsNotShownBeforeTheFirstSave(): void
    {
        // Initialize
        $typed = new SaveStaffIdDetailsCommand();
        $typed->callName = 'Teacher T';

        // Execute
        $page = $this->query(new GetMyIdCommand($this->tessId(), $typed))->data();

        // Assert
        self::assertIsArray($page);
        self::assertSame('TESS', $this->card($page)->callName);
    }

    public function testAPrincipalsPageNamesTheirDivision(): void
    {
        // Initialize
        $division = (new Division())->setRegion('Region VII')->setName('Mandaue City');
        $this->em->persist($division);
        $this->em->flush();

        // Execute
        $page = $this->query(new GetMyIdCommand((int) $this->rosa->getId()))->data();

        // Assert
        self::assertIsArray($page);
        self::assertTrue($page['isPrincipal']);
        self::assertInstanceOf(Division::class, $page['division']);
        self::assertSame($division->getId(), $page['division']->getId());
    }

    /** @param array<mixed> $page */
    private function card(array $page): StaffIdCard
    {
        self::assertInstanceOf(StaffIdCard::class, $page['card']);

        return $page['card'];
    }

    private function present(User $user, string $designation): void
    {
        $this->em->persist(
            (new ServiceRecordEntry($user, new \DateTimeImmutable('2020-01-01'), $this->rosa, new \DateTimeImmutable()))
                ->setDesignation($designation)
                ->setStatus('Regular/Permanent')
                ->setAnnualSalary('300000')
                ->setStation('Mandaue City')
                ->setBranch('National'),
        );
        $this->em->flush();
    }

    /** Gives $user a stored photo; returns its file, for the caller to delete. */
    private function photo(User $user): string
    {
        $path = 'uploads/photos/test_' . bin2hex(random_bytes(4)) . '.webp';
        $file = static::getContainer()->getParameter('app.upload_root') . '/' . $path;
        @mkdir(\dirname($file), 0o777, true);
        imagewebp(imagecreatetruecolor(35, 45), $file);
        $user->setPhotoPath($path);
        $this->em->flush();

        return $file;
    }

    private function tessId(): int
    {
        return (int) $this->tess->getId();
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
