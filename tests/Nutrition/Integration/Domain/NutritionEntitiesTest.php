<?php

declare(strict_types=1);

namespace App\Tests\Nutrition\Integration\Domain;

use App\Nutrition\Domain\Model\Repository\Sf8SubmissionRepositoryInterface;
use App\Nutrition\Domain\Model\Repository\NutritionAssessmentRepositoryInterface;
use App\Learners\Domain\Model\Enrollment;
use App\Schools\Domain\Model\GradeLevel;
use App\Nutrition\Domain\Model\NutritionAssessment;
use App\Nutrition\Domain\Model\NutritionMeasurement;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Classes\Domain\Model\Section;
use App\Nutrition\Domain\Model\Sf8Submission;
use App\Access\Domain\Model\User;
use App\Nutrition\Domain\Model\NutritionPeriod;
use App\Tests\SchoolFixturesTrait;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class NutritionEntitiesTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private Section $section;
    private User $adviser;
    private Enrollment $enrollment;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2025, current: true);
        $this->school = $this->school('303196', 'Pagsabungan National High School');
        $this->adviser = $this->plainUser('dave', User::ROLE_TEACHER);
        $this->section = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Artistic');
        $this->enrollment = $this->enroll(
            $this->learner('120017170001', 'Arnol', 'Gerald'),
            $this->sy,
            $this->section,
            $this->adviser,
        );
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    private function assessment(NutritionPeriod $period): NutritionAssessment
    {
        $assessment = new NutritionAssessment(
            $this->section,
            $period,
            new \DateTimeImmutable('2025-08-22 10:00'),
            $this->adviser,
            new \DateTimeImmutable('2025-08-22 10:00'),
        );
        $this->em->persist($assessment);
        $this->em->flush();

        return $assessment;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function found(EntityManagerInterface $em, string $class, mixed $id): object
    {
        $entity = $em->find($class, $id);
        self::assertNotNull($entity);

        return $entity;
    }

    public function testAnAssessmentKeepsWhoCreatedItAndWhen(): void
    {
        // Initialize
        $assessment = new NutritionAssessment(
            $this->section,
            NutritionPeriod::Baseline,
            new \DateTimeImmutable('2025-08-22 10:00'),
            $this->adviser,
            new \DateTimeImmutable('2025-08-22 10:30'),
        );
        $measurement = (new NutritionMeasurement($assessment, $this->enrollment))->setWeightKg('31.50');
        $assessment->addMeasurement($measurement);

        // Execute
        $this->em->persist($assessment);
        $this->em->flush();

        // Assert
        self::assertSame($this->adviser->getId(), $assessment->getCreatedBy()->getId());
        self::assertSame('2025-08-22 10:30', $assessment->getCreatedAt()->format('Y-m-d H:i'));
        self::assertNotNull($measurement->getId());
    }

    public function testPersistAssessmentWithMeasurements(): void
    {
        $other = $this->enroll(
            $this->learner('120017170002', 'Bello', 'Ana'),
            $this->sy,
            $this->section,
            $this->adviser,
        );
        $assessment = new NutritionAssessment(
            $this->section,
            NutritionPeriod::Baseline,
            new \DateTimeImmutable('2025-08-22 10:00'),
            $this->adviser,
            new \DateTimeImmutable('2025-08-22 10:00'),
        );
        $assessment->addMeasurement(
            (new NutritionMeasurement($assessment, $this->enrollment))->setWeightKg('31.50')->setHeightM('1.32'),
        );
        $assessment->addMeasurement((new NutritionMeasurement($assessment, $other))->setRemarks('Absent'));
        $this->em->persist($assessment);
        $this->em->flush();
        $id = $assessment->getId();
        $this->em->clear();

        $loaded = $this->found($this->em, NutritionAssessment::class, $id);
        self::assertSame('2025-08-22', $loaded->getWeighedOn()->format('Y-m-d'));
        self::assertSame(NutritionPeriod::Baseline, $loaded->getPeriod());
        self::assertCount(2, $loaded->getMeasurements());

        $enrollment = $this->found($this->em, Enrollment::class, $this->enrollment->getId());
        $measurement = $loaded->measurementFor($enrollment);
        self::assertNotNull($measurement);
        self::assertSame('31.50', $measurement->getWeightKg());
        self::assertSame('1.32', $measurement->getHeightM());
        self::assertTrue($measurement->isWeighed());

        $remarksOnly = $loaded->measurementFor($this->found($this->em, Enrollment::class, $other->getId()));
        self::assertNotNull($remarksOnly);
        self::assertSame('Absent', $remarksOnly->getRemarks());
        self::assertFalse($remarksOnly->isWeighed());

        $found = static::getContainer()->get(NutritionAssessmentRepositoryInterface::class);
        self::assertSame(
            $loaded,
            $found->findFor(
                $this->found($this->em, Section::class, $this->section->getId()),
                NutritionPeriod::Baseline,
            ),
        );
        self::assertNull($found->findFor($loaded->getSection(), NutritionPeriod::Endline));
    }

    public function testOneAssessmentPerSectionAndPeriod(): void
    {
        $this->assessment(NutritionPeriod::Baseline);
        $this->assessment(NutritionPeriod::Endline);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->assessment(NutritionPeriod::Baseline);
    }

    public function testSubmissionUnique(): void
    {
        $this->em->persist(
            new Sf8Submission(
                $this->section,
                NutritionPeriod::Baseline,
                $this->adviser,
                new \DateTimeImmutable('2025-08-23'),
            ),
        );
        $this->em->persist(
            new Sf8Submission(
                $this->section,
                NutritionPeriod::Endline,
                $this->adviser,
                new \DateTimeImmutable('2025-08-23'),
            ),
        );
        $this->em->flush();

        $repository = static::getContainer()->get(Sf8SubmissionRepositoryInterface::class);
        self::assertNotNull($repository->findFor($this->section, NutritionPeriod::Baseline));
        self::assertArrayHasKey(
            $this->section->getId(),
            $repository->findForSections([$this->section], NutritionPeriod::Endline),
        );

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->persist(
            new Sf8Submission(
                $this->section,
                NutritionPeriod::Baseline,
                $this->adviser,
                new \DateTimeImmutable('2025-08-24'),
            ),
        );
        $this->em->flush();
    }

    public function testUnenrollDeletesMeasurements(): void
    {
        $assessment = $this->assessment(NutritionPeriod::Baseline);
        $assessment->addMeasurement(
            (new NutritionMeasurement($assessment, $this->enrollment))->setWeightKg('31.50')->setHeightM('1.32'),
        );
        $this->em->flush();
        $assessmentId = $assessment->getId();

        $this->em->remove($this->enrollment);
        $this->em->flush();
        $this->em->clear();

        self::assertCount(0, $this->found($this->em, NutritionAssessment::class, $assessmentId)->getMeasurements());
        $count = $this->em->createQuery('SELECT COUNT(m) FROM ' . NutritionMeasurement::class . ' m');
        self::assertSame(0, (int) $count->getSingleScalarResult());
    }

    public function testCertifierFieldsBlankToNull(): void
    {
        $this->school->setSf8CertifierName('  ')->setSf8CertifierPosition(' Principal II ');

        self::assertNull($this->school->getSf8CertifierName());
        self::assertSame('Principal II', $this->school->getSf8CertifierPosition());
    }
}
