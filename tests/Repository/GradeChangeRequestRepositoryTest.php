<?php

namespace App\Tests\Repository;

use App\Entity\GradeChangeRequest;
use App\Entity\GradeLevel;
use App\Entity\GradingPeriod;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Subject;
use App\Entity\User;
use App\Repository\GradeChangeRequestRepository;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class GradeChangeRequestRepositoryTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private GradeChangeRequestRepository $requests;
    private SchoolYear $sy;
    private School $school;
    private School $other;
    private Section $honest;
    private Section $loyal;
    private Section $foreign;
    private Subject $math;
    private Subject $science;
    private GradingPeriod $term1;
    private GradingPeriod $term2;
    private User $teacher;
    private User $principal;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->requests = static::getContainer()->get(GradeChangeRequestRepository::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2025, current: true);
        $this->sy->rebuildGradingPeriods();
        $this->em->flush();
        $this->term1 = $this->sy->getGradingPeriod(1);
        $this->term2 = $this->sy->getGradingPeriod(2);
        $this->school = $this->school('303196', 'Pagsabungan National High School');
        $this->other = $this->school('303197', 'Other National High School');
        $this->teacher = $this->plainUser('dave', User::ROLE_TEACHER);
        $this->principal = $this->plainUser('rosa', User::ROLE_PRINCIPAL);
        $this->honest = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Honest');
        $this->loyal = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Loyal');
        $this->foreign = $this->newSection($this->other, $this->sy, GradeLevel::Grade8, 'Honest');
        $this->math = $this->subject($this->school, 'MATH8', 'Mathematics', 1);
        $this->science = $this->subject($this->school, 'SCI8', 'Science', 2);
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    private function subject(School $school, string $code, string $name, int $position): Subject
    {
        $subject = (new Subject($school, $this->sy))->setGradeLevel(GradeLevel::Grade8)->setCode($code)->setName($name)->setPosition($position);
        $subject->setMeetingsPerWeek(5);
        $this->em->persist($subject);
        $this->em->flush();

        return $subject;
    }

    private function request(Section $section, Subject $subject, GradingPeriod $period, string $createdAt): GradeChangeRequest
    {
        $request = new GradeChangeRequest($section, $subject, $period, $this->teacher, 'Encoding mistake in the sheet', new \DateTimeImmutable($createdAt));
        $this->em->persist($request);
        $this->em->flush();

        return $request;
    }

    public function testPendingAndDecidedSplitsWithOrder(): void
    {
        $oldPending = $this->request($this->honest, $this->math, $this->term1, '2025-08-20 08:00:00');
        $newPending = $this->request($this->loyal, $this->math, $this->term1, '2025-08-21 08:00:00');
        $olderDecided = $this->request($this->honest, $this->science, $this->term1, '2025-08-18 08:00:00');
        $newerDecided = $this->request($this->loyal, $this->science, $this->term1, '2025-08-19 08:00:00');
        $olderDecided->approve($this->principal, new \DateTimeImmutable('2025-08-22 08:00:00'));
        $newerDecided->decline($this->principal, new \DateTimeImmutable('2025-08-23 08:00:00'), 'Not enough proof');
        $this->em->flush();

        $this->assertSame([$oldPending, $newPending], $this->requests->findPendingForSchool($this->school, $this->sy));
        $this->assertSame([$newerDecided, $olderDecided], $this->requests->findDecidedForSchool($this->school, $this->sy));
        $this->assertSame(2, $this->requests->countPendingForSchool($this->school, $this->sy));
    }

    public function testFindPendingForIgnoresDecidedAndOtherClasses(): void
    {
        $decided = $this->request($this->honest, $this->math, $this->term1, '2025-08-20 08:00:00');
        $decided->withdraw(new \DateTimeImmutable('2025-08-20 09:00:00'));
        $this->em->flush();
        $this->assertNull($this->requests->findPendingFor($this->honest, $this->math, $this->term1));

        $pending = $this->request($this->honest, $this->math, $this->term1, '2025-08-21 08:00:00');
        $this->assertSame($pending, $this->requests->findPendingFor($this->honest, $this->math, $this->term1));
        $this->assertNull($this->requests->findPendingFor($this->honest, $this->math, $this->term2));
        $this->assertNull($this->requests->findPendingFor($this->loyal, $this->math, $this->term1));
    }

    public function testFindForClassNewestFirst(): void
    {
        $first = $this->request($this->honest, $this->math, $this->term1, '2025-08-20 08:00:00');
        $first->decline($this->principal, new \DateTimeImmutable('2025-08-20 09:00:00'), 'Not enough proof');
        $second = $this->request($this->honest, $this->math, $this->term1, '2025-08-21 08:00:00');
        $this->request($this->honest, $this->math, $this->term2, '2025-08-22 08:00:00');
        $this->em->flush();

        $this->assertSame([$second, $first], $this->requests->findForClass($this->honest, $this->math, $this->term1));
    }

    public function testOtherSchoolIsExcluded(): void
    {
        $this->request($this->foreign, $this->subject($this->other, 'MATH8', 'Mathematics', 1), $this->term1, '2025-08-20 08:00:00');

        $this->assertSame([], $this->requests->findPendingForSchool($this->school, $this->sy));
        $this->assertSame([], $this->requests->findDecidedForSchool($this->school, $this->sy));
        $this->assertSame(0, $this->requests->countPendingForSchool($this->school, $this->sy));
        $this->assertSame(1, $this->requests->countPendingForSchool($this->other, $this->sy));
    }
}
