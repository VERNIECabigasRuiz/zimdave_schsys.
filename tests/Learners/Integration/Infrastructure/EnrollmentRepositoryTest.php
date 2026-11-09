<?php

declare(strict_types=1);

namespace App\Tests\Learners\Integration\Infrastructure;

use App\Learners\Domain\Model\Enrollment;
use App\Schools\Domain\Model\GradeLevel;
use App\Learners\Domain\Model\Learner;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Classes\Domain\Model\Section;
use App\Learners\Domain\Model\SexAtBirth;
use App\Access\Domain\Model\User;
use App\Learners\Domain\Model\Repository\EnrollmentRepositoryInterface;
use App\Tests\SchoolFixturesTrait;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class EnrollmentRepositoryTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private EnrollmentRepositoryInterface $enrollments;
    private SchoolYear $sy;
    private School $school;
    private Section $section;
    private User $admin;
    private int $lrnSeq = 100000000000;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enrollments = static::getContainer()->get(EnrollmentRepositoryInterface::class);
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
        $learner = (new Learner($user, new \DateTimeImmutable()))
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
        $learners = [
            ['Abad', SexAtBirth::Female],
            ['Cruz', SexAtBirth::Male],
            ['Abad', SexAtBirth::Male],
            ['Cruz', SexAtBirth::Female],
        ];
        foreach ($learners as [$last, $sex]) {
            $this->em->persist(
                new Enrollment(
                    $this->learner($last, $sex->label(), $sex),
                    $this->sy,
                    $this->section,
                    $this->admin,
                    new \DateTimeImmutable(),
                ),
            );
        }
        $this->em->flush();

        $order = array_map(
            static fn (Enrollment $e) => ((string) $e->getLearner()->getSex()?->label())[0] . ' '
                . $e->getLearner()->getLastName(),
            $this->enrollments->findClassList($this->section),
        );
        self::assertSame(['M Abad', 'M Cruz', 'F Abad', 'F Cruz'], $order);
    }

    public function testClassListOrdersSameNamesByMiddleName(): void
    {
        foreach (['Zosa', 'Abella'] as $middle) {
            $learner = $this->learner('Cruz', 'Juan', SexAtBirth::Male)->setMiddleName($middle);
            $this->em->persist(
                new Enrollment($learner, $this->sy, $this->section, $this->admin, new \DateTimeImmutable()),
            );
            $this->em->flush();
        }

        $middles = array_map(
            static fn (Enrollment $e) => $e->getLearner()->getMiddleName(),
            $this->enrollments->findClassList($this->section),
        );
        self::assertSame(['Abella', 'Zosa'], $middles);
    }

    public function testOneEnrollmentPerYear(): void
    {
        $learner = $this->learner('Abad', 'Ana', SexAtBirth::Female);
        $this->em->persist(new Enrollment($learner, $this->sy, $this->section, $this->admin, new \DateTimeImmutable()));
        $this->em->flush();

        $this->em->persist(new Enrollment($learner, $this->sy, $this->section, $this->admin, new \DateTimeImmutable()));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }
}
