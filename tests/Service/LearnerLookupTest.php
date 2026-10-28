<?php

namespace App\Tests\Service;

use App\Entity\GradeLevel;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Repository\EnrollmentRepository;
use App\Repository\LearnerRepository;
use App\Service\LearnerLookup;
use App\Service\LookupKind;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class LearnerLookupTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private LearnerLookup $lookup;
    private SchoolYear $sy;
    private School $school;
    private School $other;
    private User $admin;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->lookup = new LearnerLookup($c->get(LearnerRepository::class), $c->get(EnrollmentRepository::class));
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303456', 'Pagsabungan NHS');
        $this->other = $this->school('303457', 'Mandaue NHS');
        $this->admin = $this->plainUser('boss', User::ROLE_ADMIN);
    }

    public function testNewLrn(): void
    {
        $r = $this->lookup->find('123456789012', $this->school, $this->sy);
        $this->assertSame(LookupKind::New, $r->kind);
        $this->assertNull($r->learner);
        $this->assertNull($r->enrollment);
    }

    public function testNotEnrolledThisYear(): void
    {
        $learner = $this->learner('123456789012', 'Cruz', 'Ana');
        $r = $this->lookup->find('123456789012', $this->school, $this->sy);
        $this->assertSame(LookupKind::NotEnrolled, $r->kind);
        $this->assertSame($learner, $r->learner);
        $this->assertNull($r->enrollment);
    }

    public function testEnrolledHere(): void
    {
        $learner = $this->learner('123456789012', 'Cruz', 'Ana');
        $e = $this->enroll($learner, $this->sy, $this->newSection($this->school, $this->sy, GradeLevel::Grade7, 'Rizal'), $this->admin);
        $r = $this->lookup->find('123456789012', $this->school, $this->sy);
        $this->assertSame(LookupKind::Here, $r->kind);
        $this->assertSame($learner, $r->learner);
        $this->assertSame($e, $r->enrollment);
    }

    public function testEnrolledElsewhere(): void
    {
        $learner = $this->learner('123456789012', 'Cruz', 'Ana');
        $e = $this->enroll($learner, $this->sy, $this->newSection($this->other, $this->sy, GradeLevel::Grade7, 'Bonifacio'), $this->admin);
        $r = $this->lookup->find('123456789012', $this->school, $this->sy);
        $this->assertSame(LookupKind::Elsewhere, $r->kind);
        $this->assertSame($learner, $r->learner);
        $this->assertSame($e, $r->enrollment);
    }

    public function testLrnIsTrimmed(): void
    {
        $learner = $this->learner('123456789012', 'Cruz', 'Ana');
        $r = $this->lookup->find(' 123456789012 ', $this->school, $this->sy);
        $this->assertSame($learner, $r->learner);
    }
}
