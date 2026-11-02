<?php

namespace App\Tests\Service;

use App\Entity\Enrollment;
use App\Entity\GradeLevel;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Sf1Remark;
use App\Entity\TransferRequest;
use App\Entity\TransferStatus;
use App\Entity\User;
use App\Repository\TransferRequestRepository;
use App\Sf1\Sf1Remarks;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class Sf1RemarksTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private Sf1Remarks $remarks;
    private SchoolYear $sy;
    private School $from;
    private Section $there;
    private Section $here;
    private User $admin;
    private Enrollment $enrollment;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->remarks = new Sf1Remarks(static::getContainer()->get(TransferRequestRepository::class));
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2026, current: true);
        $this->from = $this->school('303457', 'Mandaue NHS');
        $school = $this->school('303456', 'Pagsabungan NHS');
        $this->there = $this->newSection($this->from, $this->sy, GradeLevel::Grade8, 'Bonifacio');
        $this->here = $this->newSection($school, $this->sy, GradeLevel::Grade8, 'Artistic');
        $this->admin = $this->plainUser('boss', User::ROLE_ADMIN);
        $this->enrollment = $this->enroll($this->learner('123456789012', 'Cruz', 'Juan'), $this->sy, $this->there, $this->admin);
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    private function transfer(TransferStatus $status, string $decidedAt = '2026-06-16'): TransferRequest
    {
        $request = new TransferRequest($this->enrollment, $this->here, $this->admin, new \DateTimeImmutable('2026-06-15'));
        if (TransferStatus::Pending !== $status) {
            $request->decide($status, $this->admin, new \DateTimeImmutable($decidedAt));
        }
        if (TransferStatus::Approved === $status) {
            $this->enrollment->moveTo($this->here);
        }
        $this->em->persist($request);
        $this->em->flush();

        return $request;
    }

    public function testApprovedTransferGivesAutomaticTi(): void
    {
        $this->transfer(TransferStatus::Approved);

        self::assertSame('T/I Mandaue NHS 2026-06-16', $this->remarks->automaticFor($this->enrollment));
        self::assertSame('T/I Mandaue NHS 2026-06-16', $this->remarks->textFor($this->enrollment));
    }

    public function testManualRemarkWins(): void
    {
        $this->transfer(TransferStatus::Approved);
        $this->enrollment->setRemarkCode(Sf1Remark::Sned)->setRemarkDetails('Hearing impaired');

        self::assertSame('SNED Hearing impaired', $this->remarks->textFor($this->enrollment));
    }

    public function testPendingOrDeclinedGivesNothing(): void
    {
        $this->transfer(TransferStatus::Pending);
        $this->transfer(TransferStatus::Declined);

        self::assertNull($this->remarks->automaticFor($this->enrollment));
        self::assertNull($this->remarks->textFor($this->enrollment));
    }

    public function testStillTiAfterMoveWithinNewSchool(): void
    {
        $this->transfer(TransferStatus::Approved);
        $this->enrollment->moveTo($this->newSection($this->here->getSchool(), $this->sy, GradeLevel::Grade8, 'Diligence'));
        $this->em->flush();

        self::assertSame('T/I Mandaue NHS 2026-06-16', $this->remarks->textFor($this->enrollment));
    }

    public function testNoTransferGivesNull(): void
    {
        self::assertNull($this->remarks->textFor($this->enrollment));
    }
}
