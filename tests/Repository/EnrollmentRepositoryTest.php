<?php

namespace App\Tests\Repository;

use App\Entity\Enrollment;
use App\Entity\GradeLevel;
use App\Entity\Learner;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\SexAtBirth;
use App\Entity\User;
use App\Repository\EnrollmentRepository;
use App\Tests\SchoolFixturesTrait;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class EnrollmentRepositoryTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private EnrollmentRepository $enrollments;
    private SchoolYear $sy;
    private School $school;
    private Section $section;
    private User $admin;
    private int $lrnSeq = 100000000000;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enrollments = static::getContainer()->get(EnrollmentRepository::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303456', 'Pagsabungan NHS');
        $this->section = (new Section($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade7)->setName('Rizal');
        $this->em->persist($this->section);
        $this->admin = $this->plainUser('admin', User::ROLE_ADMIN);
        $this->em->flush();
    }

    private function learner(string $last, string $first, SexAtBirth $sex): Learner
    {
        $user = (new User())->setRole(User::ROLE_STUDENT)->setPassword('x');
        $learner = (new Learner($user))
            ->setLrn((string) ++$this->lrnSeq)
            ->setLastName($last)
            ->setFirstName($first)
            ->setSex($sex)
            ->setBirthDate(new \DateTimeImmutable('2012-05-01'));
        $this->em->persist($user);
        $this->em->persist($learner);

        return $learner;
    }

    public function testClassListOrder(): void
    {
        foreach ([['Abad', SexAtBirth::Female], ['Cruz', SexAtBirth::Male], ['Abad', SexAtBirth::Male], ['Cruz', SexAtBirth::Female]] as [$last, $sex]) {
            $this->em->persist(new Enrollment($this->learner($last, $sex->label(), $sex), $this->sy, $this->section, $this->admin));
        }
        $this->em->flush();

        $order = array_map(
            static fn (Enrollment $e) => $e->getLearner()->getSex()->label()[0].' '.$e->getLearner()->getLastName(),
            $this->enrollments->findClassList($this->section),
        );
        self::assertSame(['M Abad', 'M Cruz', 'F Abad', 'F Cruz'], $order);
    }

    public function testOneEnrollmentPerYear(): void
    {
        $learner = $this->learner('Abad', 'Ana', SexAtBirth::Female);
        $this->em->persist(new Enrollment($learner, $this->sy, $this->section, $this->admin));
        $this->em->flush();

        $this->em->persist(new Enrollment($learner, $this->sy, $this->section, $this->admin));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }
}
